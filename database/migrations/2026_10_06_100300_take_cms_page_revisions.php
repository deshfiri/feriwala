<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable published (or once-published) snapshots of a page (§4, §34.1).
 *
 * `content` is the complete export a publish took at that moment: every
 * enabled-or-not section in order, the resolved menu items, and the SEO
 * fields — everything the published reader needs, so it never has to join
 * back to the live, editable `cms_page_sections` and risk showing a change
 * made after this revision went live. A new edit always becomes a new row
 * here.
 *
 * `publication_state` is the one column allowed to change after the row is
 * written — scheduled becomes published, published becomes superseded —
 * reusing the same `feriwala_catalogue_columns_are_locked` trigger every
 * other locked-column table already uses, scoped to every other column.
 * `published_at` gets its own "set once" guard, also reused (from the
 * products migration): stamped the moment this revision goes live, then
 * frozen, so a later supersession cannot rewrite when it actually
 * published. The row is never deleted; a rollback creates a brand new
 * revision via `restored_from_id` rather than reviving an old one in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_page_revisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cms_page_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('version');
            $table->jsonb('content');

            $table->string('publication_state', 16);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('restored_from_id')->nullable()->constrained('cms_page_revisions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->unique(['cms_page_id', 'version']);
            $table->index(['cms_page_id', 'publication_state']);
        });

        Schema::table('cms_pages', function (Blueprint $table) {
            $table->foreignId('current_published_revision_id')->nullable()
                ->after('publication_state')
                ->constrained('cms_page_revisions')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_page_revisions
                ADD CONSTRAINT cms_page_revisions_publication_state_known CHECK (
                    publication_state IN ('scheduled', 'published', 'superseded')
                ),
                -- A revision is only ever created at the moment of publishing
                -- (now or in the future); 'scheduled' is the one state with no
                -- stamp yet, since the moment has not arrived.
                ADD CONSTRAINT cms_page_revisions_published_is_stamped CHECK (
                    (publication_state = 'scheduled') = (published_at IS NULL)
                );

            CREATE TRIGGER cms_page_revisions_locked_columns
                BEFORE UPDATE OF
                    public_id, cms_page_id, version, content, restored_from_id, created_by, reason
                ON cms_page_revisions
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'cms_page_id', 'version', 'content', 'restored_from_id', 'created_by', 'reason'
                );

            CREATE TRIGGER cms_page_revisions_published_at_set_once
                BEFORE UPDATE OF published_at ON cms_page_revisions
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_published_at_is_set_once();

            CREATE TRIGGER cms_page_revisions_no_delete
                BEFORE DELETE ON cms_page_revisions
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cms_page_revisions_no_delete ON cms_page_revisions;
            DROP TRIGGER IF EXISTS cms_page_revisions_published_at_set_once ON cms_page_revisions;
            DROP TRIGGER IF EXISTS cms_page_revisions_locked_columns ON cms_page_revisions;
        SQL);

        Schema::table('cms_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_published_revision_id');
        });

        Schema::dropIfExists('cms_page_revisions');
    }
};
