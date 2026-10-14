<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * KYC verification batches as reusable, versioned templates (D30).
 *
 * `kyc_batches` already exists as a heading that groups criteria. It becomes the
 * template header: it gains a selfie setting, a default flag and a bilingual
 * name, and now holds **groups** (each with its own completion rule), which hold
 * **requirements** (the existing `kyc_document_types`), which hold **evidence
 * slots** (the files and typed values that make one requirement complete).
 *
 * What an applicant is asked is never read from this live structure. Publishing
 * freezes it into an immutable `kyc_batch_versions` row, and a round points at
 * the version it was opened against — so editing a batch cannot change a round
 * already open.
 *
 * Existing data is mapped mechanically, never guessed: each existing batch gets
 * one "all required" group holding exactly the criteria it already held, with
 * their own required/optional flag; each criterion gets one file slot if it asked
 * for a file and one value slot if it asked for a typed value. Nobody is asked
 * for more or less than before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_batches', function (Blueprint $table) {
            $table->string('name_bn')->nullable()->after('name');
            $table->text('description_bn')->nullable()->after('description');

            // Whether a live selfie is asked, and whether a picked file may stand
            // in for the camera. Off by default: nothing changes until chosen.
            $table->string('selfie_mode', 16)->default('disabled');
            $table->boolean('selfie_allows_upload')->default(false);

            // The batch a new round opens with when none is chosen, per audience.
            $table->boolean('is_default')->default(false);

            // The newest published version; zero until first published.
            $table->unsignedSmallInteger('published_version')->default(0);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_batches
                ADD CONSTRAINT kyc_batches_selfie_mode_known CHECK (selfie_mode IN ('disabled', 'optional', 'required'));

            -- One default per audience. A batch for 'both' is its own audience here:
            -- resolution prefers the exact audience and falls back to it.
            CREATE UNIQUE INDEX kyc_batches_one_default_per_audience
                ON kyc_batches (audience) WHERE is_default AND archived_at IS NULL;
        SQL);

        Schema::table('kyc_document_types', function (Blueprint $table) {
            $table->string('name_bn')->nullable()->after('name');
            $table->text('instructions_bn')->nullable()->after('instructions');
        });

        Schema::create('kyc_requirement_slots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('kyc_document_type_id')->constrained()->cascadeOnDelete();

            // The stable handle inside the requirement: front, back, bio_page.
            $table->string('key', 48);

            $table->string('label');
            $table->string('label_bn')->nullable();
            $table->text('instructions')->nullable();
            $table->text('instructions_bn')->nullable();

            $table->boolean('is_required')->default(true);

            // What it takes: a file, an image, a PDF, a typed value, or a
            // declaration the applicant ticks.
            $table->string('evidence_type', 16);

            // Null for a typed value or a declaration.
            $table->jsonb('accepted_mime_types')->nullable();
            $table->unsignedInteger('max_size_kb')->nullable();

            $table->string('value_label')->nullable();
            $table->string('value_label_bn')->nullable();

            // A typed value that is encrypted at rest and masked on screen.
            $table->boolean('is_sensitive')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['kyc_document_type_id', 'key']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_requirement_slots
                ADD CONSTRAINT kyc_slots_evidence_type_known
                CHECK (evidence_type IN ('file', 'image', 'pdf', 'value', 'declaration'));
        SQL);

        Schema::create('kyc_batch_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('kyc_batch_id')->constrained('kyc_batches')->cascadeOnDelete();

            $table->string('key', 48);
            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->text('description')->nullable();
            $table->text('description_bn')->nullable();

            // all: every required requirement. any: at least one. min: at least
            // `minimum_required`. The server decides — never only the form.
            $table->string('completion_rule', 8)->default('all');
            $table->unsignedSmallInteger('minimum_required')->nullable();

            // Whether the group has to be satisfied at all.
            $table->boolean('is_required')->default(true);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kyc_batch_id', 'key']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_batch_groups
                ADD CONSTRAINT kyc_groups_rule_known CHECK (completion_rule IN ('all', 'any', 'min')),
                ADD CONSTRAINT kyc_groups_minimum_matches_rule CHECK (
                    (completion_rule = 'min' AND minimum_required IS NOT NULL AND minimum_required >= 1)
                    OR (completion_rule <> 'min' AND minimum_required IS NULL)
                );
        SQL);

        Schema::create('kyc_batch_group_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_batch_group_id')->constrained('kyc_batch_groups')->cascadeOnDelete();
            $table->foreignId('kyc_document_type_id')->constrained()->restrictOnDelete();

            // Only meaningful in an 'all' group: an optional item is offered but
            // not owed. In 'any' and 'min' groups every option counts towards the
            // minimum, so it is ignored there.
            $table->boolean('is_required')->default(true);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['kyc_batch_group_id', 'kyc_document_type_id'], 'kyc_group_requirement_unique');
        });

        Schema::create('kyc_batch_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('kyc_batch_id')->constrained('kyc_batches')->restrictOnDelete();
            $table->unsignedSmallInteger('version');

            // The whole structure as it stood: batch, groups, requirements,
            // slots, selfie rule, audience and applicability. A round reads this
            // and nothing else.
            $table->jsonb('definition');
            $table->string('checksum', 64);

            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['kyc_batch_id', 'version']);
        });

        // Append-only: a published version is the record of what was asked.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kyc_batch_versions_no_update
                BEFORE UPDATE ON kyc_batch_versions
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER kyc_batch_versions_no_delete
                BEFORE DELETE ON kyc_batch_versions
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);

        $this->backfill();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS kyc_batch_versions_no_update ON kyc_batch_versions;
            DROP TRIGGER IF EXISTS kyc_batch_versions_no_delete ON kyc_batch_versions;
        SQL);

        Schema::dropIfExists('kyc_batch_versions');
        Schema::dropIfExists('kyc_batch_group_requirements');
        Schema::dropIfExists('kyc_batch_groups');
        Schema::dropIfExists('kyc_requirement_slots');

        Schema::table('kyc_document_types', function (Blueprint $table) {
            $table->dropColumn(['name_bn', 'instructions_bn']);
        });

        DB::unprepared('DROP INDEX IF EXISTS kyc_batches_one_default_per_audience');
        DB::unprepared('ALTER TABLE kyc_batches DROP CONSTRAINT IF EXISTS kyc_batches_selfie_mode_known');

        Schema::table('kyc_batches', function (Blueprint $table) {
            $table->dropColumn(['name_bn', 'description_bn', 'selfie_mode', 'selfie_allows_upload', 'is_default', 'published_version']);
        });
    }

    /**
     * One file slot per criterion that asked for a file, one value slot per one
     * that asked for a typed value; one "all required" group per batch holding
     * the criteria it already held.
     */
    protected function backfill(): void
    {
        $now = now();

        foreach (DB::table('kyc_document_types')->whereNull('archived_at')->orderBy('id')->get() as $type) {
            $mimes = json_decode((string) $type->accepted_mime_types, true) ?: [];
            $order = 0;

            if ($type->requires_file) {
                $imagesOnly = $mimes !== [] && array_filter($mimes, fn ($mime) => ! str_starts_with((string) $mime, 'image/')) === [];
                $pdfOnly = $mimes === ['application/pdf'];

                DB::table('kyc_requirement_slots')->insert([
                    'public_id' => (string) Str::ulid(),
                    'kyc_document_type_id' => $type->id,
                    'key' => 'file',
                    'label' => $type->name,
                    'instructions' => $type->instructions,
                    'is_required' => true,
                    'evidence_type' => $imagesOnly ? 'image' : ($pdfOnly ? 'pdf' : 'file'),
                    'accepted_mime_types' => json_encode($mimes),
                    'max_size_kb' => $type->max_size_kb,
                    'is_sensitive' => false,
                    'sort_order' => $order++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($type->requires_value) {
                DB::table('kyc_requirement_slots')->insert([
                    'public_id' => (string) Str::ulid(),
                    'kyc_document_type_id' => $type->id,
                    'key' => 'value',
                    'label' => $type->value_label ?? $type->name,
                    'is_required' => true,
                    'evidence_type' => 'value',
                    'value_label' => $type->value_label,
                    // Typed answers have always been encrypted and masked.
                    'is_sensitive' => true,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Criteria outside any batch are still asked of applicants today, so they
        // are grouped rather than dropped — one batch per audience that has them.
        foreach (DB::table('kyc_document_types')->whereNull('kyc_batch_id')->whereNull('archived_at')->distinct()->pluck('audience') as $audience) {
            $batchId = DB::table('kyc_batches')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'key' => "legacy_ungrouped_{$audience}",
                'audience' => $audience,
                'name' => 'Ungrouped requirements (legacy)',
                'description' => 'Requirements that were not in any batch before batches were versioned.',
                'is_active' => true,
                'sort_order' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('kyc_document_types')
                ->whereNull('kyc_batch_id')
                ->whereNull('archived_at')
                ->where('audience', $audience)
                ->update(['kyc_batch_id' => $batchId]);
        }

        foreach (DB::table('kyc_batches')->orderBy('id')->get() as $batch) {
            $groupId = DB::table('kyc_batch_groups')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'kyc_batch_id' => $batch->id,
                'key' => 'requirements',
                'name' => 'Requirements',
                'completion_rule' => 'all',
                'is_required' => true,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (DB::table('kyc_document_types')->where('kyc_batch_id', $batch->id)->whereNull('archived_at')->orderBy('sort_order')->orderBy('id')->get() as $position => $type) {
                DB::table('kyc_batch_group_requirements')->insert([
                    'kyc_batch_group_id' => $groupId,
                    'kyc_document_type_id' => $type->id,
                    'is_required' => $type->is_required,
                    'sort_order' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
