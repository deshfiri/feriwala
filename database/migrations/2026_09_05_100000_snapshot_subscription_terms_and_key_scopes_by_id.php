<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two invariants that were not holding (§8.1, §8.3, §36.2).
 *
 * **1. A subscription now carries its own terms.**
 *
 * `Entitlements` read the live package row, so an administrator lowering a
 * staff limit changed what every existing subscriber was entitled to,
 * retroactively and silently. §8.3 makes a change of terms an upgrade or a
 * renewal — something an account agrees to — not something that happens to
 * them between one request and the next.
 *
 * The money already paid was safe: payments, allocations and tax lines all
 * snapshot at the moment of charge. What was missing was everything the
 * subscription *grants*, plus the terms an invoice or a renewal quote reads
 * back — name, fees, deposit, minimum balance, charges, currency.
 *
 * Stored as one JSON document rather than thirty columns because it is read
 * whole and never queried across: "what did this account buy" is one question.
 *
 * **2. KYC scope rules stop keying on a mutable slug.**
 *
 * `kyc_document_type_scopes.package_slug` made a display concern into a
 * relationship key. Renaming a package's slug — a routing decision — silently
 * orphaned every verification rule pointing at it, and nothing said so until an
 * applicant was asked for the wrong documents.
 *
 * The public id is a ULID assigned once and never changed, so it can carry the
 * relationship while the slug goes back to being what it is: the public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_packages', function (Blueprint $table) {
            // Null for rows created before snapshots existed; Entitlements
            // falls back to the live package for those rather than granting
            // nothing, which would strand real accounts mid-term.
            $table->json('terms')->nullable()->after('currency_code');
            $table->timestamp('terms_captured_at')->nullable()->after('terms');
        });

        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            /*
             * `string`, not `ulid`. A ULID column is `char(26)`, and PostgreSQL
             * pads a `char` to its full width — so a value written short comes
             * back with trailing spaces and an equality check that looks
             * obviously correct quietly fails. The column holds a ULID; it does
             * not need a fixed-width type to do so.
             */
            $table->string('package_public_id', 26)->nullable()->after('kyc_document_type_id');
            $table->index('package_public_id');
        });

        DB::statement('
            UPDATE kyc_document_type_scopes
            SET package_public_id = packages.public_id
            FROM packages
            WHERE packages.slug = kyc_document_type_scopes.package_slug
        ');

        /*
         * A rule whose slug resolved to nothing is dropped, not carried across
         * with a null id — it already matched nobody, and keeping it would
         * leave a rule that looks package-scoped and behaves globally.
         */
        DB::table('kyc_document_type_scopes')
            ->whereNotNull('package_slug')
            ->whereNull('package_public_id')
            ->delete();

        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            $table->dropColumn('package_slug');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            $table->string('package_slug')->nullable()->after('kyc_document_type_id');
        });

        DB::statement('
            UPDATE kyc_document_type_scopes
            SET package_slug = packages.slug
            FROM packages
            WHERE packages.public_id = kyc_document_type_scopes.package_public_id
        ');

        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            $table->dropIndex(['package_public_id']);
            $table->dropColumn('package_public_id');
        });

        Schema::table('user_packages', function (Blueprint $table) {
            $table->dropColumn(['terms', 'terms_captured_at']);
        });
    }
};
