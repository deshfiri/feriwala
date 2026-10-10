<?php

namespace App\Domain\ContentLibrary\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\ContentLibrary\ContentBlocks;
use App\Domain\ContentLibrary\Models\ContentLibraryItem;
use App\Domain\ContentLibrary\Policies\ContentLibraryPolicy;
use App\Domain\Storage\Models\StoredFile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Releasing library content to Products, and changing what was released.
 *
 * Released immediately: there is no draft step, so creating an item publishes it
 * to every Product chosen, and an edit changes what partners see at once. The
 * blocks are rebuilt from the vocabulary in {@see ContentBlocks} — any key or
 * block type outside it is dropped, never stored — and every file a block names
 * must be a library upload of the right kind.
 */
class SaveContentLibraryItem
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  list<string>  $productIds  Product public ids; at least one
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(User $actor, ?ContentLibraryItem $item, string $title, array $blocks, array $productIds): ContentLibraryItem
    {
        ContentLibraryPolicy::authorize(
            $item === null ? ContentLibraryPolicy::canPublish($actor) : ContentLibraryPolicy::canEdit($actor),
            $item === null ? 'You may not publish library content.' : 'You may not edit library content.',
        );

        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('A title is required.');
        }

        $products = Product::query()->whereIn('public_id', array_values(array_unique($productIds)))->get();

        if ($products->isEmpty() || $products->count() !== count(array_unique($productIds))) {
            throw new InvalidArgumentException('Choose at least one existing Product to release this content to.');
        }

        $clean = $this->clean($blocks);

        return $this->database->transaction(function () use ($actor, $item, $title, $clean, $products) {
            $before = $item === null ? null : ['title' => $item->title, 'products' => $item->products()->pluck('products.public_id')->all()];

            if ($item === null) {
                $item = ContentLibraryItem::create([
                    'title' => $title,
                    'blocks' => $clean,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                    'published_at' => now(),
                ]);
            } else {
                $item->forceFill(['title' => $title, 'blocks' => $clean, 'updated_by' => $actor->id])->save();
            }

            $item->products()->sync($products->pluck('id')->all());

            $this->audit->handle(new AuditEntry(
                action: $before === null ? 'content_library.published' : 'content_library.updated',
                actorId: $actor->id,
                auditableType: ContentLibraryItem::class,
                auditableId: $item->id,
                before: $before,
                after: ['title' => $title, 'products' => $products->pluck('public_id')->all(), 'blocks' => count($clean)],
                module: 'content_library',
            ));

            return $item;
        });
    }

    /**
     * Rebuild every block from the allowed keys of its own type.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    protected function clean(array $blocks): array
    {
        if ($blocks === [] || count($blocks) > ContentBlocks::MAX_BLOCKS) {
            throw new InvalidArgumentException('Add between 1 and '.ContentBlocks::MAX_BLOCKS.' blocks.');
        }

        $clean = [];

        foreach (array_values($blocks) as $block) {
            $type = $block['type'] ?? null;

            $clean[] = match ($type) {
                'text' => ['type' => 'text', 'text' => $this->text($block, 'text', ContentBlocks::TEXT_MAX)],
                'image' => [
                    'type' => 'image',
                    'file_id' => $this->file($block, ContentBlocks::IMAGE_TYPES),
                    'alt' => $this->optional($block, 'alt', 255),
                    'caption' => $this->optional($block, 'caption', 500),
                ],
                'video' => $this->video($block),
                'link' => [
                    'type' => 'link',
                    'url' => $this->url($block['url'] ?? null),
                    'label' => $this->text($block, 'label', 255),
                    'description' => $this->optional($block, 'description', 500),
                ],
                default => throw new InvalidArgumentException('Unknown content block type.'),
            };
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    protected function video(array $block): array
    {
        $hasFile = filled($block['file_id'] ?? null);
        $hasUrl = filled($block['url'] ?? null);

        if ($hasFile === $hasUrl) {
            throw new InvalidArgumentException('A video block needs either an uploaded video or a link, not both.');
        }

        return $hasFile
            ? ['type' => 'video', 'file_id' => $this->file($block, ContentBlocks::VIDEO_TYPES), 'caption' => $this->optional($block, 'caption', 500)]
            : ['type' => 'video', 'url' => $this->url($block['url']), 'caption' => $this->optional($block, 'caption', 500)];
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function text(array $block, string $key, int $max): string
    {
        $value = trim((string) ($block[$key] ?? ''));

        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("A {$key} of 1 to {$max} characters is required.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function optional(array $block, string $key, int $max): ?string
    {
        $value = trim((string) ($block[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function url(mixed $value): string
    {
        $url = trim((string) $value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url === '' || mb_strlen($url) > 2000 || ! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Links must be full http or https addresses.');
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $mimeTypes
     */
    protected function file(array $block, array $mimeTypes): string
    {
        $id = (string) ($block['file_id'] ?? '');

        $exists = StoredFile::query()
            ->where('public_id', $id)
            ->where('purpose', ContentBlocks::PURPOSE)
            ->whereIn('mime_type', $mimeTypes)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('One of the uploaded files is missing or is the wrong kind for its block.');
        }

        return $id;
    }
}
