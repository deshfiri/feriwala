<?php

namespace App\Domain\Storage\Enums;

use App\Domain\Storage\ManagedStorage;

/**
 * Whether a managed file may be served to anyone with its address, or only
 * through a controller that checks permission first (beta-critical batch,
 * Commit 4).
 *
 * A property of the file, not of whichever disk happens to be active today:
 * the same visibility holds whether the file sits on the local `public`/
 * `private` disks or on Cloudflare R2, which is what lets
 * {@see ManagedStorage} choose the right disk without
 * every caller knowing which backend is live.
 */
enum StorageVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
