<?php

namespace App\Domain\ContentLibrary;

use App\Domain\Storage\ManagedStorage;
use App\Domain\Storage\Models\StoredFile;
use Illuminate\Support\Collection;

/**
 * The block vocabulary of a library item, and how a stored block becomes what a
 * page renders.
 *
 * A block is one of:
 *
 *   - `text`  — `text`, plain; line breaks kept, nothing interpreted as markup;
 *   - `image` — `file_id` (a managed file's public id), optional `alt`, `caption`;
 *   - `video` — either `file_id` (an uploaded MP4 or WebM) or `url` (an https
 *     link), optional `caption`;
 *   - `link`  — `url` (http or https only), `label`, optional `description`.
 *
 * Content is data, never HTML: nothing here is ever rendered as markup, so there
 * is nothing to sanitise and no script a block could carry. Links are limited to
 * http(s) so a `javascript:` address cannot be published.
 */
class ContentBlocks
{
    public const TYPES = ['text', 'image', 'video', 'link'];

    /**
     * No cap on how many blocks, how long a text, or how big a file: nothing in
     * the library is limited by this application. (The web server's own upload
     * ceiling still applies to a single file.) Only what a browser may be handed
     * safely is restricted: SVG is left out because it can carry script.
     */
    public const NO_LIMIT = PHP_INT_MAX;

    /** @var list<string> */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    /** @var list<string> */
    public const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];

    /** Where every library upload lives, so a block can only name such a file. */
    public const PURPOSE = 'content-library';

    public function __construct(protected ManagedStorage $storage) {}

    /**
     * Every managed-file id the blocks refer to.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<string>
     */
    public static function fileIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            if (filled($block['file_id'] ?? null)) {
                $ids[] = (string) $block['file_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Blocks as the editor needs them back: exactly as stored, plus a preview
     * address (and mime type) for each block that holds a file.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public function forEditor(array $blocks): array
    {
        $files = StoredFile::query()
            ->whereIn('public_id', self::fileIds($blocks))
            ->get()
            ->keyBy('public_id');

        return array_map(function (array $block) use ($files) {
            $file = $files->get((string) ($block['file_id'] ?? ''));

            return $file === null
                ? $block
                : [...$block, 'url' => $this->storage->url($file), 'mime_type' => $file->mime_type];
        }, array_values($blocks));
    }

    /**
     * Blocks as a page renders them: file ids resolved to addresses, and a
     * YouTube or Vimeo link to its embeddable address. Unknown or missing files
     * are dropped rather than shown broken.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public function present(array $blocks): array
    {
        $files = StoredFile::query()
            ->whereIn('public_id', self::fileIds($blocks))
            ->get()
            ->keyBy('public_id');

        $presented = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;

            $out = match ($type) {
                'text' => ['type' => 'text', 'text' => (string) ($block['text'] ?? '')],
                'link' => [
                    'type' => 'link',
                    'url' => (string) ($block['url'] ?? ''),
                    'label' => (string) ($block['label'] ?? ($block['url'] ?? '')),
                    'description' => $block['description'] ?? null,
                ],
                'image' => $this->presentFile($block, $files, 'image'),
                'video' => $this->presentVideo($block, $files),
                default => null,
            };

            if ($out !== null) {
                $presented[] = $out;
            }
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<string, StoredFile>  $files
     * @return array<string, mixed>|null
     */
    protected function presentFile(array $block, $files, string $type): ?array
    {
        $file = $files->get((string) ($block['file_id'] ?? ''));
        if ($file === null) {
            return null;
        }

        $url = $this->storage->url($file);

        if ($url === null) {
            return null;
        }

        return [
            'type' => $type,
            'url' => $url,
            'mime_type' => $file->mime_type,
            'alt' => $block['alt'] ?? null,
            'caption' => $block['caption'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<string, StoredFile>  $files
     * @return array<string, mixed>|null
     */
    protected function presentVideo(array $block, $files): ?array
    {
        if (filled($block['file_id'] ?? null)) {
            return $this->presentFile($block, $files, 'video');
        }

        $url = (string) ($block['url'] ?? '');

        if ($url === '') {
            return null;
        }

        return [
            'type' => 'video',
            'url' => $url,
            'embed_url' => self::embedUrl($url),
            'mime_type' => null,
            'caption' => $block['caption'] ?? null,
        ];
    }

    /**
     * The embeddable address for a YouTube or Vimeo link, or null for anything
     * else — which is then shown as a plain link instead of being framed.
     */
    public static function embedUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = (string) ($parts['path'] ?? '');

        if (in_array($host, ['youtube.com', 'm.youtube.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $id = $query['v'] ?? null;

            if ($id === null && preg_match('#^/(?:embed|shorts)/([A-Za-z0-9_-]{6,20})#', $path, $match) === 1) {
                $id = $match[1];
            }

            return is_string($id) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) === 1
                ? 'https://www.youtube-nocookie.com/embed/'.$id
                : null;
        }

        if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{6,20})$#', $path, $match) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$match[1];
        }

        if ($host === 'vimeo.com' && preg_match('#^/(\d{5,12})$#', $path, $match) === 1) {
            return 'https://player.vimeo.com/video/'.$match[1];
        }

        return null;
    }
}
