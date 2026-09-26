<?php

declare(strict_types=1);

namespace App\Cms;

use App\Audit\AuditService;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Support\Application;
use App\Support\Db;
use App\Support\ImageOptimizer;
use App\Support\Ulid;

/**
 * The media library: public images for pages, snippets and link previews.
 *
 * Every upload is treated as hostile until proven otherwise:
 *  - size is checked before anything is read; the type is taken from the file's bytes (finfo + magic bytes), never from the
 *    name or the browser; only JPEG, PNG, WebP and GIF; the pixel count is checked from the header before decoding (no
 *    decompression bombs);
 *  - the image is decoded and **re-encoded** with GD: metadata (EXIF GPS…) and anything appended after the image data (a
 *    "polyglot" PHP or HTML payload) is gone; EXIF rotation is applied first; long sides are capped; GIFs become PNGs;
 *  - files get random names under <root>/<yyyy>/<mm>/ — nothing the uploader typed ever reaches a path;
 *  - a WebP copy and a thumbnail are made; the same bytes uploaded twice reuse the first copy.
 * Uploading needs `cms.manage` (writers need pictures); deleting needs `cms.publish` and is refused while a page, snippet or
 * menu still uses the file.
 */
final class MediaLibrary
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'png'];
    public const PER_PAGE = 40;

    public function __construct(
        private readonly Db $db,
        private readonly Application $app,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
    ) {
    }

    // ---- reading ----------------------------------------------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int,bytes:int} */
    public function page(string $search, int $page): array
    {
        $where = '1 = 1';
        $bind = [];
        if ($search !== '') {
            $where = '(m.original_name LIKE :q1 OR m.alt_text LIKE :q2 OR m.title LIKE :q3)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $bind = ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        $limit = self::PER_PAGE;
        $offset = (max(1, $page) - 1) * $limit;
        $rows = $this->db->select("SELECT m.*, u.name AS uploaded_by_name FROM cms_media m LEFT JOIN users u ON u.id = m.uploaded_by WHERE {$where}
            ORDER BY m.created_at DESC, m.id DESC LIMIT {$limit} OFFSET {$offset}", $bind);

        return [
            'rows' => array_map(fn (array $r): array => $r + $this->urls($r), $rows),
            'total' => (int) $this->db->selectValue("SELECT COUNT(*) FROM cms_media m WHERE {$where}", $bind),
            'bytes' => (int) $this->db->selectValue('SELECT COALESCE(SUM(size_bytes), 0) FROM cms_media', [], 0),
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $publicId): ?array
    {
        $r = $this->db->selectOne('SELECT m.*, u.name AS uploaded_by_name FROM cms_media m LEFT JOIN users u ON u.id = m.uploaded_by WHERE m.public_id = :p', ['p' => $publicId]);

        return $r === null ? null : $r + $this->urls($r);
    }

    /** Where a file is used: pages (content or featured image), snippets and menus. @return list<string> */
    public function usage(array $media): array
    {
        $url = $this->url((string) $media['path']);
        $like = '%' . addcslashes($url, '%_\\') . '%';
        $out = [];
        foreach ($this->db->select('SELECT title, path FROM cms_pages WHERE deleted_at IS NULL AND (body_source LIKE :a OR featured_image LIKE :b) LIMIT 20', ['a' => $like, 'b' => $like]) as $p) {
            $out[] = 'Page: ' . $p['title'] . ' (/' . $p['path'] . ')';
        }
        foreach ($this->db->select('SELECT title FROM cms_snippets WHERE body_source LIKE :a LIMIT 20', ['a' => $like]) as $s) {
            $out[] = 'Snippet: ' . $s['title'];
        }
        if ((string) setting('business.share_image', '') !== '' && str_contains((string) setting('business.share_image', ''), $url)) {
            $out[] = 'Settings: link-preview image';
        }

        return $out;
    }

    // ---- writing ----------------------------------------------------------------------------------------------

    /**
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file an entry of $_FILES
     * @return array{media:array<string,mixed>,reused:bool}
     * @throws AuthorizationException|ValidationException
     */
    public function upload(array $file, string $alt, User $actor): array
    {
        if (!$this->permissions->userCan($actor, 'cms.manage')) {
            throw AuthorizationException::forPermission('cms.manage');
        }
        if (!ImageOptimizer::gdAvailable()) {
            throw new ValidationException(['file' => ['Image processing (the PHP GD extension) is not available on this server.']]);
        }
        $cfg = (array) $this->app->config()->get('cms.media', []);
        $tmp = (string) ($file['tmp_name'] ?? '');
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $maxBytes = (int) ($cfg['max_kb'] ?? 8192) * 1024;

        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['file' => ['The file is too large (' . (int) ($cfg['max_kb'] ?? 8192) / 1024 . ' MB at most).']]);
        }
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            throw new ValidationException(['file' => ['Choose an image to upload.']]);
        }
        if ($this->app->isProduction() && !is_uploaded_file($tmp)) {
            throw new ValidationException(['file' => ['That is not an uploaded file.']]);
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $maxBytes) {
            throw new ValidationException(['file' => ['The file is empty or too large (' . round($maxBytes / 1048576, 1) . ' MB at most).']]);
        }
        $alt = trim($alt);
        if (mb_strlen($alt) > 200 || preg_match('/[\x00-\x1F\x7F]/', $alt) === 1) {
            throw new ValidationException(['alt' => ['The description is one line of up to 200 characters.']]);
        }

        $bytes = (string) file_get_contents($tmp);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::TYPES[$mime]) || !$this->magicMatches($mime, $bytes)) {
            throw new ValidationException(['file' => ['Only JPEG, PNG, WebP and GIF images can be uploaded (SVG and other formats are refused).']]);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new ValidationException(['file' => ['The image could not be read — it may be damaged.']]);
        }
        if ($info[0] * $info[1] > (int) ($cfg['max_pixels'] ?? 40_000_000)) {
            throw new ValidationException(['file' => ['The image has too many pixels (' . number_format($info[0]) . '×' . number_format($info[1]) . ').']]);
        }

        $sha = hash('sha256', $bytes);
        $existing = $this->db->selectOne('SELECT public_id FROM cms_media WHERE sha256 = :s', ['s' => $sha]);
        if ($existing !== null) {
            return ['media' => (array) $this->find((string) $existing['public_id']), 'reused' => true];
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ValidationException(['file' => ['The image could not be decoded — it may be damaged.']]);
        }
        try {
            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);   // GIF / palette PNG: WebP and resampling need true colour
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);
            if ($mime === 'image/jpeg') {
                $image = $this->orient($image, ImageOptimizer::exifOrientation($bytes));
            }
            $image = $this->fit($image, (int) ($cfg['max_side'] ?? 2560));
            $ext = self::TYPES[$mime];
            $publicId = Ulid::generate();
            $dir = gmdate('Y') . '/' . gmdate('m');
            $name = strtolower($publicId);
            $root = $this->root();
            if (!is_dir($root . '/' . $dir) && !@mkdir($root . '/' . $dir, 0755, true) && !is_dir($root . '/' . $dir)) {
                throw new ValidationException(['file' => ['The media folder is not writable on the server.']]);
            }
            $written = [];
            $main = "{$dir}/{$name}.{$ext}";
            $this->encode($image, $ext, $root . '/' . $main, $cfg);
            $written[] = $root . '/' . $main;
            $webp = null;
            if ($ext !== 'webp' && function_exists('imagewebp')) {
                $webp = "{$dir}/{$name}.webp";
                $this->encode($image, 'webp', $root . '/' . $webp, $cfg);
                $written[] = $root . '/' . $webp;
            }
            $thumbImg = $this->fit($this->copy($image), (int) ($cfg['thumb_side'] ?? 400));
            $thumbExt = function_exists('imagewebp') ? 'webp' : 'jpg';
            $thumb = "{$dir}/{$name}-thumb.{$thumbExt}";
            $this->encode($thumbImg, $thumbExt, $root . '/' . $thumb, $cfg);
            imagedestroy($thumbImg);
            $written[] = $root . '/' . $thumb;

            $original = $this->cleanName((string) ($file['name'] ?? 'image'));
            try {
                $id = (int) $this->db->insertRow('cms_media', [
                    'public_id' => $publicId, 'path' => $main, 'webp_path' => $webp, 'thumb_path' => $thumb, 'original_name' => $original,
                    'mime' => $ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext, 'size_bytes' => (int) filesize($root . '/' . $main),
                    'width' => imagesx($image), 'height' => imagesy($image), 'alt_text' => $alt === '' ? null : $alt, 'sha256' => $sha, 'uploaded_by' => $actor->id,
                ]);
            } catch (\Throwable $e) {
                foreach ($written as $f) {
                    @unlink($f);
                }
                throw $e;
            }
        } finally {
            imagedestroy($image);
        }
        $this->audit->log('cms_media_uploaded', 'cms', 'cms_media', $id, null, ['name' => $original, 'path' => $main], null, $actor);

        return ['media' => (array) $this->find($publicId), 'reused' => false];
    }

    /** @throws AuthorizationException|DomainRuleException|ValidationException */
    public function describe(string $publicId, string $alt, string $title, User $actor): void
    {
        if (!$this->permissions->userCan($actor, 'cms.manage')) {
            throw AuthorizationException::forPermission('cms.manage');
        }
        $m = $this->find($publicId) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That file no longer exists.', [], 404);
        $alt = trim($alt);
        $title = trim($title);
        $errors = [];
        if (mb_strlen($alt) > 200 || preg_match('/[\x00-\x1F\x7F]/', $alt) === 1) {
            $errors['alt'] = ['The description is one line of up to 200 characters.'];
        }
        if (mb_strlen($title) > 120 || preg_match('/[\x00-\x1F\x7F]/', $title) === 1) {
            $errors['title'] = ['The title is one line of up to 120 characters.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->db->affectingStatement('UPDATE cms_media SET alt_text = :a, title = :t WHERE id = :id', ['a' => $alt === '' ? null : $alt, 't' => $title === '' ? null : $title, 'id' => $m['id']]);
        $this->audit->log('cms_media_described', 'cms', 'cms_media', (int) $m['id'], ['alt' => $m['alt_text']], ['alt' => $alt], null, $actor);
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function delete(string $publicId, User $actor): void
    {
        if (!$this->permissions->userCan($actor, 'cms.publish')) {
            throw AuthorizationException::forPermission('cms.publish');
        }
        $m = $this->find($publicId) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'That file no longer exists.', [], 404);
        $used = $this->usage($m);
        if ($used !== []) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Still in use — ' . implode('; ', array_slice($used, 0, 3)) . '. Remove it there first.', [], 422);
        }
        $this->db->affectingStatement('DELETE FROM cms_media WHERE id = :id', ['id' => $m['id']]);
        foreach (['path', 'webp_path', 'thumb_path'] as $col) {
            if (!empty($m[$col])) {
                $this->unlinkInside((string) $m[$col]);
            }
        }
        $this->audit->log('cms_media_deleted', 'cms', 'cms_media', (int) $m['id'], ['name' => $m['original_name'], 'path' => $m['path']], null, null, $actor);
    }

    // ---- internals --------------------------------------------------------------------------------------------

    public function root(): string
    {
        return rtrim((string) $this->app->config()->get('cms.media.root', $this->app->basePath('public/media')), '/\\');
    }

    public function url(string $relative): string
    {
        return rtrim((string) $this->app->config()->get('cms.media.url', '/media'), '/') . '/' . $relative;
    }

    /** @param array<string,mixed> $r @return array{url:string,webp_url:?string,thumb_url:string} */
    private function urls(array $r): array
    {
        return [
            'url' => $this->url((string) $r['path']),
            'webp_url' => $r['webp_path'] ? $this->url((string) $r['webp_path']) : null,
            'thumb_url' => $this->url((string) ($r['thumb_path'] ?: $r['path'])),
        ];
    }

    private function magicMatches(string $mime, string $b): bool
    {
        return match ($mime) {
            'image/jpeg' => str_starts_with($b, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($b, "\x89PNG\r\n\x1A\n"),
            'image/gif' => str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a'),
            'image/webp' => str_starts_with($b, 'RIFF') && substr($b, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private function orient(\GdImage $img, int $o): \GdImage
    {
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }

    private function fit(\GdImage $img, int $maxSide): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) <= $maxSide) {
            return $img;
        }
        $scale = $maxSide / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = $this->canvas($nw, $nh);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $out;
    }

    private function copy(\GdImage $img): \GdImage
    {
        $out = $this->canvas(imagesx($img), imagesy($img));
        imagecopy($out, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        return $out;
    }

    private function canvas(int $w, int $h): \GdImage
    {
        $c = imagecreatetruecolor($w, $h);
        imagealphablending($c, false);
        imagesavealpha($c, true);
        imagefill($c, 0, 0, imagecolorallocatealpha($c, 0, 0, 0, 127));

        return $c;
    }

    /** @param array<string,mixed> $cfg */
    private function encode(\GdImage $img, string $ext, string $path, array $cfg): void
    {
        imagesavealpha($img, true);
        $ok = match ($ext) {
            'jpg' => imagejpeg($this->flatten($img), $path, (int) ($cfg['jpeg_quality'] ?? 82)),
            'png' => imagepng($img, $path, 6),
            'webp' => imagewebp($img, $path, (int) ($cfg['webp_quality'] ?? 80)),
            default => false,
        };
        if (!$ok) {
            throw new ValidationException(['file' => ['The image could not be saved on the server.']]);
        }
    }

    /** JPEG has no transparency: put the image on white. */
    private function flatten(\GdImage $img): \GdImage
    {
        $out = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        return $out;
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $name);
        $name = trim($name, ' .');

        return $name === '' ? 'image' : mb_substr($name, 0, 200);
    }

    private function unlinkInside(string $relative): void
    {
        if (str_contains($relative, '..') || preg_match('#^\d{4}/\d{2}/[a-z0-9-]+\.(?:jpg|png|webp)$#D', $relative) !== 1) {
            return;   // never delete anything that does not look like one of our own files
        }
        @unlink($this->root() . '/' . $relative);
    }
}
