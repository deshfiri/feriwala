<?php

namespace App\Domain\ContentLibrary\Actions;

use App\Domain\ContentLibrary\ContentBlocks;
use App\Domain\ContentLibrary\Policies\ContentLibraryPolicy;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Domain\Storage\Models\StoredFile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;

/**
 * Stores an image or video chosen in the editor, before the item it will sit in
 * is saved, so the editor can show it straight away. The file is only a library
 * upload until a saved item's block names it.
 *
 * Public, the same way product media is: partner pages render it directly, and
 * the gate that matters is who may reach the Product page.
 */
class UploadContentLibraryFile
{
    public function __construct(protected StoreManagedFile $store) {}

    /**
     * @param  'image'|'video'  $kind
     *
     * @throws AuthorizationException
     * @throws UnacceptableFile
     */
    public function handle(User $actor, UploadedFile $file, string $kind): StoredFile
    {
        ContentLibraryPolicy::authorize(
            ContentLibraryPolicy::canPublish($actor) || ContentLibraryPolicy::canEdit($actor),
            'You may not upload library content.',
        );

        $isImage = $kind === 'image';

        return $this->store->handle(
            file: $file,
            purpose: ContentBlocks::PURPOSE,
            visibility: StorageVisibility::Public,
            allowedMimeTypes: $isImage ? ContentBlocks::IMAGE_TYPES : ContentBlocks::VIDEO_TYPES,
            maxBytes: $isImage ? ContentBlocks::IMAGE_MAX_BYTES : ContentBlocks::VIDEO_MAX_BYTES,
            createdBy: $actor->id,
        );
    }
}
