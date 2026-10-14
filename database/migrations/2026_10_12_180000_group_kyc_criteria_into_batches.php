<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Verification batches (§7.2).
 *
 * An administrator groups KYC criteria into batches — Identity, Business,
 * Address — and decides who each batch applies to. Applicants complete every
 * batch that applies to them in one submission.
 *
 * Existing criteria are not lost or regrouped by guesswork: each audience that
 * already has criteria gets one "General verification" batch holding them all,
 * with no rules, so nobody is asked for more or less than before. The
 * per-criterion country and package rules stay in force; the administrator
 * moves criteria into better batches at their own pace.
 *
 * Submissions already taken keep reading as they did — their requirement
 * snapshots gain batch columns that are simply empty, and an empty batch renders
 * as no heading at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // The stable handle, like a criterion's key.
            $table->string('key', 64)->unique();

            // Who is asked: account, supplier or both. Every criterion in the
            // batch takes this audience.
            $table->string('audience', 16)->default('account');

            $table->string('name');
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        DB::statement("ALTER TABLE kyc_batches ADD CONSTRAINT kyc_batches_audience_known CHECK (audience IN ('account', 'supplier', 'both'))");

        // Who a batch applies to. Empty means everyone; otherwise any one match.
        Schema::create('kyc_batch_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_batch_id')->constrained('kyc_batches')->cascadeOnDelete();

            // Null matches anything. Both null is never stored.
            $table->string('package_public_id', 26)->nullable();
            $table->string('country_code', 2)->nullable();

            $table->timestamps();

            $table->index(['kyc_batch_id']);
        });

        Schema::table('kyc_document_types', function (Blueprint $table) {
            // Nullable so a criterion made outside the admin screen — a seeder,
            // a test — still works; the admin screen always sets it.
            $table->foreignId('kyc_batch_id')->nullable()->after('id')->constrained('kyc_batches')->restrictOnDelete();
        });

        foreach (['kyc_submission_requirements', 'supplier_kyc_requirements'] as $snapshot) {
            Schema::table($snapshot, function (Blueprint $table) {
                // What batch the requirement sat in when it was asked for, copied
                // like the rest of the snapshot so a later rename changes nothing
                // about a submission already made.
                $table->foreignId('kyc_batch_id')->nullable()->constrained('kyc_batches')->nullOnDelete();
                $table->string('batch_key', 64)->nullable();
                $table->string('batch_name')->nullable();
                $table->text('batch_description')->nullable();
                $table->unsignedSmallInteger('batch_sort_order')->default(0);
            });
        }

        $this->backfillGeneralBatches();
    }

    public function down(): void
    {
        foreach (['kyc_submission_requirements', 'supplier_kyc_requirements'] as $snapshot) {
            Schema::table($snapshot, function (Blueprint $table) {
                $table->dropConstrainedForeignId('kyc_batch_id');
                $table->dropColumn(['batch_key', 'batch_name', 'batch_description', 'batch_sort_order']);
            });
        }

        Schema::table('kyc_document_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kyc_batch_id');
        });

        Schema::dropIfExists('kyc_batch_scopes');
        Schema::dropIfExists('kyc_batches');
    }

    /**
     * One batch per audience that already has criteria, holding all of them.
     */
    protected function backfillGeneralBatches(): void
    {
        $names = ['account' => 'General verification', 'supplier' => 'Supplier verification', 'both' => 'General verification (all)'];
        $order = 0;

        foreach (DB::table('kyc_document_types')->distinct()->pluck('audience') as $audience) {
            $id = DB::table('kyc_batches')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'key' => $audience === 'account' ? 'general' : "general_{$audience}",
                'audience' => $audience,
                'name' => $names[$audience] ?? 'General verification',
                'is_active' => true,
                'sort_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('kyc_document_types')->where('audience', $audience)->update(['kyc_batch_id' => $id]);
        }
    }
};
