<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-dimensional KYC scoping, and a snapshot of what each round asked for
 * (§7.2).
 *
 * **Scoping.** `kyc_document_type_scopes` held one dimension per row —
 * `scope_type` plus `scope_value` — which can say "for Bangladesh" or "for the
 * Enterprise package" but not "for Enterprise accounts in Bangladesh". §7.2
 * asks for both, so a scope row now carries a nullable package **and** a
 * nullable country, and a row with both set means both must hold.
 *
 * A scope may also override `is_required`. A trade licence can be optional in
 * general and mandatory in one country, and forcing that into two document
 * types would show applicants the same requirement twice.
 *
 * **Snapshotting.** `kyc_submission_requirements` records what a round was
 * opened against. Without it, an administrator editing a document type
 * retroactively changes what a submitted round was judged on: a reviewer reads
 * "passport required" beside a submission made when it was optional, and an
 * applicant who complied is shown as incomplete. The round keeps the rules it
 * was given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_document_types', function (Blueprint $table) {
            // Retired rather than deleted once a submission references it: the
            // round has to keep meaning what it meant (§7.3, §36.2).
            $table->timestamp('archived_at')->nullable()->after('is_active');
        });

        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            $table->string('package_slug')->nullable()->after('kyc_document_type_id');
            $table->string('country_code', 2)->nullable()->after('package_slug');

            // Null means "use the type's own setting" — the safe fallback. A
            // boolean default would silently make every scoped requirement
            // optional, or every one mandatory, on the day this ran.
            $table->boolean('is_required')->nullable()->after('country_code');

            $table->index(['package_slug', 'country_code']);
        });

        // Carry the single-dimension rows across before the old columns go.
        DB::table('kyc_document_type_scopes')->where('scope_type', 'package')
            ->update(['package_slug' => DB::raw('scope_value')]);

        DB::table('kyc_document_type_scopes')->where('scope_type', 'country')
            ->update(['country_code' => DB::raw('upper(scope_value)')]);

        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            $table->dropColumn(['scope_type', 'scope_value']);
        });

        Schema::create('kyc_submission_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();

            // The type is referenced for joining uploads back, and every field
            // an applicant or reviewer reads is **copied**, so a later edit
            // cannot rewrite a round that has already been judged.
            $table->foreignId('kyc_document_type_id')->constrained()->cascadeOnDelete();

            $table->string('key');
            $table->string('name');
            $table->text('instructions')->nullable();

            $table->boolean('is_required');
            $table->boolean('requires_file');
            $table->boolean('requires_value');
            $table->string('value_label')->nullable();

            $table->json('accepted_mime_types');
            $table->unsignedInteger('max_size_kb');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // One requirement per type per round.
            $table->unique(['kyc_submission_id', 'kyc_document_type_id'], 'kyc_round_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_submission_requirements');

        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            $table->string('scope_type')->nullable();
            $table->string('scope_value')->nullable();
        });

        DB::table('kyc_document_type_scopes')->whereNotNull('package_slug')
            ->update(['scope_type' => 'package', 'scope_value' => DB::raw('package_slug')]);

        DB::table('kyc_document_type_scopes')->whereNotNull('country_code')
            ->update(['scope_type' => 'country', 'scope_value' => DB::raw('country_code')]);

        Schema::table('kyc_document_type_scopes', function (Blueprint $table) {
            $table->dropIndex(['package_slug', 'country_code']);
            $table->dropColumn(['package_slug', 'country_code', 'is_required']);
        });

        Schema::table('kyc_document_types', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
