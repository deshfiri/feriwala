<?php

namespace App\Domain\Storage\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * One existing table the R2 migration command (beta-critical batch,
 * Commit 5) knows how to read and update.
 *
 * Column names only -- the command reads and writes through plain
 * `getAttribute()`/`setAttribute()` rather than a shared interface, since
 * every table here already existed with its own shape before this batch
 * and none of them should be made to implement a new contract just to be
 * migrated once.
 */
final class MigrationSourceDefinition
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  'public'|'private'  $defaultVisibility  used for every row when `$visibilityColumn` is null
     */
    public function __construct(
        public readonly string $key,
        public readonly string $modelClass,
        public readonly string $diskColumn,
        public readonly string $pathColumn,
        public readonly string $sizeColumn,
        public readonly ?string $checksumColumn,
        public readonly string $defaultVisibility,
        public readonly ?string $visibilityColumn = null,
    ) {}
}
