<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier KYC follows the administrator's document catalogue.
 *
 * `kyc_document_types.audience` says who a requirement is asked of: Client/
 * Partner accounts (every existing row, so nothing changes for them), Suppliers,
 * or both. A Supplier's round then snapshots what it was opened against in
 * `supplier_kyc_requirements`, exactly as `kyc_submission_requirements` does for
 * accounts, so editing a type later cannot rewrite a round already judged.
 *
 * Typed (non-file) answers live in `supplier_kyc_fields`, encrypted at rest like
 * `kyc_submission_fields`. Both are keyed by the requirement's `key`, which is
 * what `supplier_kyc_documents.document_type` has always held.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_document_types', function (Blueprint $table) {
            $table->string('audience', 16)->default('account')->after('key');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_document_types
                ADD CONSTRAINT kyc_document_types_audience_known CHECK (audience IN ('account', 'supplier', 'both'));
        SQL);

        Schema::create('supplier_kyc_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_kyc_submission_id')->constrained()->cascadeOnDelete();

            // Null for the built-in fallback list used while no Supplier
            // requirement has been configured.
            $table->foreignId('kyc_document_type_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('key', 64);
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

            $table->unique(['supplier_kyc_submission_id', 'key'], 'supplier_kyc_round_requirement_unique');
        });

        Schema::create('supplier_kyc_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_kyc_submission_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->text('value');
            $table->timestamps();

            $table->unique(['supplier_kyc_submission_id', 'key'], 'supplier_kyc_field_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_kyc_fields');
        Schema::dropIfExists('supplier_kyc_requirements');

        DB::unprepared('ALTER TABLE kyc_document_types DROP CONSTRAINT IF EXISTS kyc_document_types_audience_known');

        Schema::table('kyc_document_types', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
