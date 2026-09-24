<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\PermissionService;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\BlogRepository;
use App\Support\BlogFormatter;
use App\Support\Db;
use App\Support\Slug;
use App\Support\Ulid;

/**
 * Writing and publishing blog posts.
 *
 *  - a post is a draft until someone with `blog.manage` publishes it; the public site sees only published posts;
 *  - what the author types is kept as `body_source`; the HTML the public sees is generated from it (BlogFormatter) on
 *    every save, so it can never contain anything the formatter would not produce;
 *  - the URL slug can be changed while the post has never been published, and is frozen afterwards (links and search
 *    rankings depend on it);
 *  - draft ⇄ published, and draft/published → archived; an archived post must be restored (to draft) before it is edited;
 *  - `published_at` is set the first time a post goes live and kept through unpublish/republish, so the byline date is stable.
 */
final class BlogService
{
    public function __construct(
        private readonly BlogRepository $posts,
        private readonly PermissionService $permissions,
        private readonly AuditService $audit,
        private readonly Db $db,
    ) {
    }

    /**
     * @param array<string,mixed> $input title, body, excerpt, slug
     * @return string the new post's public id
     * @throws AuthorizationException|ValidationException
     */
    public function create(array $input, User $actor): string
    {
        $this->assertMay($actor);
        $data = $this->validated($input);
        $publicId = Ulid::generate();
        $slug = $this->uniqueSlug($data['slug'] !== '' ? $data['slug'] : $data['title'], $publicId, 0);

        $id = $this->db->transaction(function () use ($data, $publicId, $slug, $actor): int {
            $id = $this->posts->create([
                'public_id' => $publicId, 'slug' => $slug, 'title' => $data['title'], 'excerpt' => $data['excerpt'],
                'body_source' => $data['body'], 'body_html' => BlogFormatter::toHtml($data['body']), 'status' => 'draft', 'author_id' => $actor->id,
            ]);
            $this->audit->log('blog_created', 'blog', 'blog_post', $id, null, ['title' => $data['title'], 'slug' => $slug], null, $actor);

            return $id;
        });
        unset($id);

        return $publicId;
    }

    /**
     * @param array<string,mixed> $input
     * @throws AuthorizationException|DomainRuleException|ValidationException
     */
    public function update(string $publicId, array $input, User $actor): void
    {
        $this->assertMay($actor);
        $post = $this->load($publicId);
        if ($post['status'] === 'archived') {
            throw new DomainRuleException(DomainRuleException::INVALID_TRANSITION, 'Restore this post to a draft before editing it.', [], 422);
        }
        $data = $this->validated($input);

        $changes = [
            'title' => $data['title'], 'excerpt' => $data['excerpt'],
            'body_source' => $data['body'], 'body_html' => BlogFormatter::toHtml($data['body']),
        ];
        if ($post['published_at'] === null && $data['slug'] !== '' && $data['slug'] !== $post['slug']) {
            $changes['slug'] = $this->uniqueSlug($data['slug'], (string) $post['public_id'], (int) $post['id']);
        }

        $this->db->transaction(function () use ($post, $changes, $actor): void {
            $this->posts->update((int) $post['id'], $changes);
            $this->audit->log('blog_updated', 'blog', 'blog_post', (int) $post['id'], ['title' => $post['title'], 'slug' => $post['slug']], ['title' => $changes['title'], 'slug' => $changes['slug'] ?? $post['slug']], null, $actor);
        });
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function publish(string $publicId, User $actor): void
    {
        $this->move($publicId, $actor, ['draft', 'archived'], 'published', 'blog_published', 'Only a draft or an archived post can be published.');
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function unpublish(string $publicId, User $actor): void
    {
        $this->move($publicId, $actor, ['published', 'archived'], 'draft', 'blog_unpublished', 'Only a published or archived post can be moved back to draft.');
    }

    /** @throws AuthorizationException|DomainRuleException */
    public function archive(string $publicId, User $actor): void
    {
        $this->move($publicId, $actor, ['draft', 'published'], 'archived', 'blog_archived', 'Only a draft or a published post can be archived.');
    }

    /**
     * @param list<string> $from
     * @throws AuthorizationException|DomainRuleException
     */
    private function move(string $publicId, User $actor, array $from, string $to, string $action, string $message): void
    {
        $this->assertMay($actor);
        $post = $this->load($publicId);
        if (!in_array($post['status'], $from, true)) {
            throw new DomainRuleException(DomainRuleException::INVALID_TRANSITION, $message, [], 422);
        }

        $changes = ['status' => $to];
        if ($to === 'published' && $post['published_at'] === null) {
            $changes['published_at'] = gmdate('Y-m-d H:i:s');
        }
        $this->db->transaction(function () use ($post, $changes, $to, $action, $actor): void {
            $this->posts->update((int) $post['id'], $changes);
            $this->audit->log($action, 'blog', 'blog_post', (int) $post['id'], ['status' => $post['status']], ['status' => $to, 'title' => $post['title']], null, $actor);
        });
    }

    /** @throws AuthorizationException */
    private function assertMay(User $actor): void
    {
        if (!$this->permissions->userCan($actor, 'blog.manage')) {
            throw AuthorizationException::forPermission('blog.manage');
        }
    }

    /** @return array<string,mixed> */
    private function load(string $publicId): array
    {
        return $this->posts->find($publicId) ?? throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Post not found.', [], 404);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{title:string,body:string,excerpt:?string,slug:string}
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['body'] ?? '')));
        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));

        if ($title === '' || mb_strlen($title) > 180) {
            $errors['title'] = ['Give the post a title of up to 180 characters.'];
        }
        if ($body === '') {
            $errors['body'] = ['Write something in the post.'];
        } elseif (mb_strlen($body) > 60000) {
            $errors['body'] = ['The post is too long (60,000 characters at most).'];
        } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $body) === 1) {
            $errors['body'] = ['The post contains characters that are not allowed.'];
        }
        if (mb_strlen($excerpt) > 300) {
            $errors['excerpt'] = ['The summary must be at most 300 characters.'];
        } elseif (str_contains($excerpt, "\n")) {
            $errors['excerpt'] = ['The summary must be a single line.'];
        }
        if ($slug !== '' && (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 150)) {
            $errors['slug'] = ['The address may use lowercase letters, numbers and single hyphens only (150 characters at most).'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'body' => $body, 'excerpt' => $excerpt !== '' ? $excerpt : null, 'slug' => $slug];
    }

    /** A free slug from $text: "my-title", then "my-title-2", "my-title-3"… (never one another post already uses). */
    private function uniqueSlug(string $text, string $publicId, int $exceptId): string
    {
        $base = Slug::make($text, 150);
        if ($base === 'job') {   // Slug's fallback when nothing usable is left (e.g. a title with no Latin letters)
            $base = 'post-' . strtolower(substr($publicId, -6));
        }
        $slug = $base;
        for ($n = 2; $this->posts->slugExists($slug, $exceptId); $n++) {
            $slug = substr($base, 0, 150 - strlen((string) $n) - 1) . '-' . $n;
        }

        return $slug;
    }
}
