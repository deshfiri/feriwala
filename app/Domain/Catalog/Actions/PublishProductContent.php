<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContent;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;

/**
 * An administrator publishing one update against a product (new feature:
 * per-product announcements).
 *
 * Published immediately — there is no draft or schedule to this, only a
 * running feed every operating partner reads from the product's own page.
 * At most one attachment, image or file, stored through the shared managed
 * storage abstraction so it follows the same disk (local or R2) as every
 * other catalogue file.
 *
 * Public the same way product media already is: a partner storefront reads
 * these without an authorisation check on the file itself, and the single
 * gate that actually matters — whether this account may see the product's
 * page at all — is the existing `business.activated` middleware the
 * catalogue route already carries.
 */
class PublishProductContent
{
    /** @var array<int, string> */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var array<int, string> */
    public const FILE_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    public const FILE_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        protected StoreManagedFile $storeFile,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws UnacceptableFile
     */
    public function handle(
        User $actor,
        Product $product,
        string $title,
        ?string $body,
        ?UploadedFile $image,
        ?UploadedFile $file,
    ): ProductContent {
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not publish product content.');

        return $this->database->transaction(function () use ($actor, $product, $title, $body, $image, $file) {
            $content = ProductContent::create([
                'product_id' => $product->id,
                'created_by' => $actor->id,
                'title' => $title,
                'body' => $body,
                'published_at' => now(),
            ]);

            $upload = $image ?? $file;

            if ($upload instanceof UploadedFile) {
                $isImage = $upload === $image;

                $this->storeFile->handle(
                    file: $upload,
                    purpose: "product-content/{$product->public_id}",
                    visibility: StorageVisibility::Public,
                    allowedMimeTypes: $isImage ? self::IMAGE_TYPES : self::FILE_TYPES,
                    maxBytes: $isImage ? self::IMAGE_MAX_BYTES : self::FILE_MAX_BYTES,
                    fileable: $content,
                    createdBy: $actor->id,
                );
            }

            $this->audit->handle(new AuditEntry(
                action: 'catalog.product_content_published',
                actorId: $actor->id,
                auditableType: ProductContent::class,
                auditableId: $content->id,
                after: ['product_id' => $product->id, 'title' => $title],
                module: 'catalog',
            ));

            return $content->load('attachment');
        });
    }
}
