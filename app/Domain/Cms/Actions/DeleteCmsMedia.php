<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Models\Media;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Removes an uploaded asset's file and row together, so an admin can never
 * be left with a database row whose file is gone, or a file nothing in the
 * database still points at.
 */
class DeleteCmsMedia
{
    public function __construct(protected FilesystemFactory $filesystem) {}

    public function handle(Media $media): void
    {
        $this->filesystem->disk($media->disk)->delete($media->path);

        $media->delete();
    }
}
