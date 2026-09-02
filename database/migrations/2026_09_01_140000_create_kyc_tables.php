<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC verification (§7).
 *
 * Document requirements are data, not code: §7.2 lets an administrator create
 * document types, mark them required or optional, set accepted formats and size
 * limits, and scope them to a package or a country. Hard-coding "National ID,
 * passport, proof of address" would mean a deploy every time a rule changes.
 *
 * Nothing here is deletable by ordinary means. A submission and its review
 * history are the evidence behind an activation decision (§7.3, §36.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // What an administrator asks for (§7.2).
        Schema::create('kyc_document_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('instructions')->nullable();

            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);

            // Stored rather than assumed so an administrator can allow PDFs for
            // a trade licence but images only for a photograph.
            $table->jsonb('accepted_mime_types');
            $table->unsignedInteger('max_size_kb')->default(5120);

            // Some documents are a file, some are just a number (TIN).
            $table->boolean('requires_file')->default(true);
            $table->boolean('requires_value')->default(false);
            $table->string('value_label')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // Package- and country-specific applicability (§7.2).
        Schema::create('kyc_document_type_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_document_type_id')->constrained()->cascadeOnDelete();

            // 'package' or 'country'. A type with no scope rows applies to
            // everyone — the common case, kept free of extra rows.
            $table->string('scope_type', 20);
            $table->string('scope_value', 64);

            $table->timestamps();

            $table->unique(['kyc_document_type_id', 'scope_type', 'scope_value'], 'kyc_type_scope_unique');
        });

        // One round of submission by one account (§7.3).
        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32);

            // Resubmission creates a new round rather than overwriting the last
            // one, so a reviewer can see what changed between attempts.
            $table->unsignedSmallInteger('round')->default(1);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // §7.4: a configurable window to complete or update KYC.
            $table->timestamp('deadline_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'round']);
            $table->index(['status', 'submitted_at']);
        });

        // Non-file answers — TIN, bank account number, nominee name.
        Schema::create('kyc_submission_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kyc_document_type_id')->constrained()->cascadeOnDelete();

            // Encrypted at rest: these are identity numbers and bank details
            // (§7.5, §36).
            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['kyc_submission_id', 'kyc_document_type_id'], 'kyc_field_unique');
        });

        // Uploaded files (§7.5).
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kyc_document_type_id')->constrained()->cascadeOnDelete();

            // Path on a private disk. Never a URL, and never under public/.
            $table->string('disk', 32);
            $table->string('path');

            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedInteger('size_bytes');

            // Detects silent corruption and proves the stored file is the one
            // that was reviewed.
            $table->string('checksum', 64);

            $table->boolean('is_encrypted')->default(true);

            $table->timestamps();

            $table->index(['kyc_submission_id', 'kyc_document_type_id']);
        });

        // Every reviewer decision, with the §7.3 field set.
        Schema::create('kyc_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            $table->text('reason')->nullable();

            // Separate columns, never one field with a flag — a private note
            // such as "documents look doctored" must not reach the applicant.
            $table->text('internal_note')->nullable();
            $table->text('user_visible_feedback')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['kyc_submission_id', 'created_at']);
        });

        // §7.5: record every view or download of a document.
        Schema::create('kyc_document_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accessed_by')->nullable()->constrained('users')->nullOnDelete();

            // 'view' or 'download' — a reviewer opening a photograph is not the
            // same as taking a copy away.
            $table->string('action', 16);

            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['kyc_document_id', 'created_at']);
            $table->index(['accessed_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_document_accesses');
        Schema::dropIfExists('kyc_reviews');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_submission_fields');
        Schema::dropIfExists('kyc_submissions');
        Schema::dropIfExists('kyc_document_type_scopes');
        Schema::dropIfExists('kyc_document_types');
    }
};
