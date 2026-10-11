<?php

namespace App\Domain\ContentLibrary\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\ContentLibrary\Models\ContentLibraryItem;
use App\Domain\ContentLibrary\Policies\ContentLibraryPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Takes library content down from every Product at once.
 *
 * A soft delete: partners stop seeing it immediately, and the item, who
 * published it and when stay as the record. Its uploaded files are left where
 * they are.
 */
class DeleteContentLibraryItem
{
    public function __construct(protected RecordAuditLog $audit) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, ContentLibraryItem $item): void
    {
        ContentLibraryPolicy::authorize(ContentLibraryPolicy::canDelete($actor), 'You may not remove library content.');

        $this->audit->handle(new AuditEntry(
            action: 'content_library.removed',
            actorId: $actor->id,
            auditableType: ContentLibraryItem::class,
            auditableId: $item->id,
            before: ['title' => $item->title, 'products' => $item->products()->pluck('products.public_id')->all()],
            module: 'content_library',
        ));

        $item->delete();
    }
}
