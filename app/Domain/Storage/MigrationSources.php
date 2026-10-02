<?php

namespace App\Domain\Storage;

use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Storage\Data\MigrationSourceDefinition;
use App\Domain\Storage\Models\StoredFile;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierListingMedia;

/**
 * Every existing table the R2 migration command (beta-critical batch,
 * Commit 5) is able to move -- deliberately a closed, hand-verified list
 * rather than a directory walk.
 *
 * Reading these tables' own `disk`/`path`/size/checksum columns -- never
 * scanning the filesystem -- is what keeps this command away from
 * framework assets, Vite's build output, logs, cache, sessions, `vendor/`,
 * `node_modules/` and source-controlled static files: none of those are
 * rows in any of these tables, so there is nothing to exclude.
 *
 * Deliberately excludes two real surfaces that have no metadata columns to
 * verify against at all -- a storefront's logo/banner path and the
 * platform's own branding logo/favicon, where a file predating the shared
 * storage abstraction (Commit 4) is only a bare path string with no
 * recorded size or checksum. Migrating those would mean trusting an
 * unverified copy; they are left for a human to re-upload through the
 * screens that already write through the shared abstraction, which gives
 * them a `stored_files` row (and therefore a migration path) going
 * forward.
 */
class MigrationSources
{
    /**
     * @return list<MigrationSourceDefinition>
     */
    public static function all(): array
    {
        return [
            new MigrationSourceDefinition(
                key: 'product_media',
                modelClass: ProductMedia::class,
                diskColumn: 'disk',
                pathColumn: 'path',
                sizeColumn: 'size_bytes',
                checksumColumn: null,
                defaultVisibility: 'public',
            ),
            new MigrationSourceDefinition(
                key: 'supplier_listing_media',
                modelClass: SupplierListingMedia::class,
                diskColumn: 'disk',
                pathColumn: 'path',
                sizeColumn: 'size_bytes',
                checksumColumn: null,
                defaultVisibility: 'private',
            ),
            new MigrationSourceDefinition(
                key: 'kyc_documents',
                modelClass: KycDocument::class,
                diskColumn: 'disk',
                pathColumn: 'path',
                sizeColumn: 'size_bytes',
                checksumColumn: 'checksum',
                defaultVisibility: 'private',
            ),
            new MigrationSourceDefinition(
                key: 'supplier_kyc_documents',
                modelClass: SupplierKycDocument::class,
                diskColumn: 'disk',
                pathColumn: 'path',
                sizeColumn: 'size_bytes',
                checksumColumn: 'checksum',
                defaultVisibility: 'private',
            ),
            new MigrationSourceDefinition(
                key: 'stored_files',
                modelClass: StoredFile::class,
                diskColumn: 'disk',
                pathColumn: 'path',
                sizeColumn: 'size_bytes',
                checksumColumn: 'checksum',
                defaultVisibility: 'private',
                visibilityColumn: 'visibility',
            ),
        ];
    }
}
