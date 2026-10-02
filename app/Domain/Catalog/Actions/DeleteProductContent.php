<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\ProductContent;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Storage\Actions\DeleteManagedFile;
use App\Domain\Storage\Models\StoredFile;
use App\Models\User;

/**
 * Removing a published product update (new feature: per-product
 * announcements). The attachment, if any, is captured before the row goes —
 * {@see StoredFile} has no cascade tied to this table — and its bytes are
 * removed only once the row deletion is recorded.
 */
class DeleteProductContent
{
    public function __construct(
        protected DeleteManagedFile $deleteFile,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor, ProductContent $content): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not remove product content.');

        $attachment = $content->attachment;

        $this->audit->handle(new AuditEntry(
            action: 'catalog.product_content_removed',
            actorId: $actor->id,
            auditableType: ProductContent::class,
            auditableId: $content->id,
            before: ['product_id' => $content->product_id, 'title' => $content->title],
            module: 'catalog',
        ));

        $content->delete();

        if ($attachment !== null) {
            $this->deleteFile->handle($attachment);
        }
    }
}
