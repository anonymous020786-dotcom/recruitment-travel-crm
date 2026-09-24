<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;

/**
 * The candidate-document accept pipeline (docs/00-ARCHITECTURE.md §11):
 * every upload is treated as hostile until it passes every check below, in
 * order, fail-closed. Nothing here trusts client-supplied metadata — not the
 * filename's extension, not the browser's Content-Type, only what this class
 * independently derives from the file's own bytes.
 *
 * The extension used for storage is never read from the client filename —
 * it is looked up from MIME_MAP by the server-detected MIME type. This
 * sidesteps the classic double-extension attack (`invoice.php.pdf`)
 * architecturally rather than by pattern-matching the client's filename.
 */
final class DocumentUpload
{
    /** MIME => [extension, magic-byte signature to verify at offset 0] */
    private const MIME_MAP = [
        'application/pdf' => ['ext' => 'pdf', 'sig' => "%PDF-"],
        'image/jpeg'       => ['ext' => 'jpg', 'sig' => "\xFF\xD8\xFF"],
        'image/png'        => ['ext' => 'png', 'sig' => "\x89PNG\r\n\x1a\n"],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['ext' => 'docx', 'sig' => "PK\x03\x04"],
    ];

    private const MAX_IMAGE_DIMENSION = 6000;

    public function __construct(
        private readonly Application $app,
        private readonly Logger $logger,
    ) {
    }

    public function absolutePath(string $storagePath): string
    {
        return $this->app->basePath($storagePath);
    }

    /**
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file raw $_FILES entry
     * @param list<string> $allowedMime
     * @return array{storage_path:string,mime_type:string,extension:string,size_bytes:int,sha256:string}
     */
    public function store(array $file, array $allowedMime, int $maxSizeKb): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => [$this->uploadErrorMessage($error)]]);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new ValidationException(['file' => ['No file was uploaded.']]);
        }
        // Fail closed: in production the path must be one PHP itself received over HTTP, never a path the
        // request could have named. (Outside production a plain temp file is accepted so tests and scripts work.)
        if ($this->app->isProduction() && !is_uploaded_file($tmpPath)) {
            $this->logger->warning('Document upload rejected: not an HTTP upload');

            throw new ValidationException(['file' => ['No file was uploaded.']]);
        }

        $size = (int) filesize($tmpPath); // the real size on disk, not the client-reported one
        if ($size <= 0) {
            throw new ValidationException(['file' => ['The uploaded file is empty.']]);
        }
        if ($size > $maxSizeKb * 1024) {
            throw new ValidationException(['file' => ["File exceeds the {$maxSizeKb} KB limit for this document type."]]);
        }

        $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);
        if (!in_array($mime, $allowedMime, true) || !isset(self::MIME_MAP[$mime])) {
            $this->logger->warning('Document upload rejected: MIME not allowed', ['mime' => $mime]);

            throw new ValidationException(['file' => ['That file type is not accepted for this document.']]);
        }

        $spec = self::MIME_MAP[$mime];
        $head = (string) file_get_contents($tmpPath, false, null, 0, strlen($spec['sig']));
        if (!str_starts_with($head, $spec['sig'])) {
            $this->logger->warning('Document upload rejected: signature mismatch', ['mime' => $mime]);

            throw new ValidationException(['file' => ['The file content does not match its type.']]);
        }

        if ($mime === 'application/pdf') {
            $this->assertPdfHasNoActiveContent($tmpPath);
        }

        $sourcePath = $tmpPath;
        if (str_starts_with($mime, 'image/')) {
            $sourcePath = $this->reencodeImage($tmpPath, $mime);
        }

        $sha256 = hash_file('sha256', $sourcePath);
        $extension = $spec['ext'];
        $destRelative = $this->destinationPath($extension);
        $destAbsolute = $this->app->basePath($destRelative);

        $destDir = dirname($destAbsolute);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0700, true);
        }

        // Store the bytes that were hashed and validated. For an image that is the re-encoded copy (metadata and
        // trailing data stripped) — never the original upload, which used to be moved into place instead.
        if ($sourcePath !== $tmpPath) {
            $moved = copy($sourcePath, $destAbsolute);
            @unlink($sourcePath);
        } else {
            $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $destAbsolute) : copy($tmpPath, $destAbsolute);
        }

        if (!$moved) {
            throw new \RuntimeException('Could not store the uploaded document.');
        }
        @chmod($destAbsolute, 0600);

        return [
            'storage_path' => $destRelative,
            'mime_type'    => $mime,
            'extension'    => $extension,
            'size_bytes'   => (int) filesize($destAbsolute),
            'sha256'       => (string) $sha256,
        ];
    }

    private function destinationPath(string $extension): string
    {
        $ulid = Ulid::generate();
        $shard = substr($ulid, -2);

        return sprintf('storage/private/documents/%s/%s/%s/%s.%s', date('y'), date('m'), $shard, $ulid, $extension);
    }

    /**
     * Best-effort scan for tokens indicating embedded active content or hidden content. Not a substitute for a
     * real PDF parser: keys inside compressed object streams are invisible to it. The real defences are that a
     * stored PDF is never executed or rendered server-side and is always served with nosniff.
     *
     * PDF names may hide characters as `#xx` (`/J#61vaScript` is `/JavaScript`), so those are decoded first,
     * and a token only counts as a whole name (`/JS` matches, `/JSON` does not).
     */
    private function assertPdfHasNoActiveContent(string $path): void
    {
        $contents = (string) file_get_contents($path);
        $contents = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $contents);

        if (preg_match('#/(JavaScript|JS|OpenAction|Launch|RichMedia|EmbeddedFile|Encrypt)(?![A-Za-z0-9])|/AA\s*<<#', $contents, $m) === 1) {
            $token = ($m[1] ?? '') !== '' ? $m[1] : 'AA'; // group 1 is unset when the /AA << alternative matched
            $this->logger->warning('Document upload rejected: active or hidden content in PDF', ['token' => $token]);

            throw new ValidationException(['file' => [$token === 'Encrypt'
                ? 'Encrypted or password-protected PDFs cannot be accepted. Upload an unprotected copy.'
                : 'This PDF contains embedded active content and cannot be accepted.']]);
        }
    }

    /**
     * Re-decodes and re-encodes the image to strip EXIF/metadata and any
     * embedded scripts, and caps its dimensions. Returns the path to the
     * re-encoded temp file (caller stores it, then removes it).
     *
     * If the `gd` extension is unavailable on this host, this control is
     * skipped (logged) rather than failing every image upload — the
     * signature + finfo checks above still gate the file's real type.
     */
    private function reencodeImage(string $tmpPath, string $mime): string
    {
        if (!extension_loaded('gd')) {
            $this->logger->warning('Image re-encode skipped: gd extension not available on this host');

            return $tmpPath;
        }

        // Decoding allocates ~4 bytes per pixel *before* we could look at the size, so a tiny file that declares
        // 30000 x 30000 pixels (a decompression bomb) would exhaust memory. Read the declared size from the header
        // first and refuse anything that would not fit.
        $info = @getimagesize($tmpPath);
        if ($info === false) {
            throw new ValidationException(['file' => ['That image could not be processed.']]);
        }
        $this->assertDecodable((int) $info[0], (int) $info[1]);

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            default      => false,
        };
        if ($image === false) {
            throw new ValidationException(['file' => ['That image could not be processed.']]);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION) {
            $scale = min(self::MAX_IMAGE_DIMENSION / $width, self::MAX_IMAGE_DIMENSION / $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            if ($mime === 'image/png') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $out = tempnam(sys_get_temp_dir(), 'doc_reencode');
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $out, 90),
            'image/png'  => imagepng($image, $out, 6),
            default      => false,
        };
        imagedestroy($image);

        if (!$ok) {
            throw new \RuntimeException('Could not re-encode the uploaded image.');
        }

        return $out;
    }

    /** @throws ValidationException when decoding a width x height image could not fit in the remaining memory */
    private function assertDecodable(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw new ValidationException(['file' => ['That image could not be processed.']]);
        }

        $need = $width * $height * 5;                  // 4 bytes/pixel for the bitmap plus working space
        $limit = self::memoryLimitBytes();
        if ($limit > 0 && memory_get_usage() + $need > (int) ($limit * 0.8)) {
            $this->logger->warning('Document upload rejected: image too large to decode', ['width' => $width, 'height' => $height]);

            throw new ValidationException(['file' => ['That image has too many pixels. Reduce its resolution and try again.']]);
        }
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0; // unlimited
        }
        $n = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            default => 'The upload could not be processed.',
        };
    }
}
