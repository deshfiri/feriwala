<?php

use App\Domain\Cms\Actions\CancelScheduledPublish;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens `cms_page_revisions_published_is_stamped` for a revision that was
 * scheduled and then cancelled without ever going live (Stage 7 addendum,
 * §34.1) — {@see CancelScheduledPublish} moves such
 * a revision straight to `superseded` with `published_at` still null,
 * since it was never actually published. The original constraint required
 * every non-scheduled revision to carry a stamp, which held only because
 * nothing before this addendum ever reached `superseded` any other way
 * than through `published`.
 *
 * The new rule: `published` must carry a stamp, `scheduled` must not, and
 * `superseded` may or may not — it depends on whether the revision it
 * replaced ever actually went live.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_page_revisions
                DROP CONSTRAINT cms_page_revisions_published_is_stamped,
                ADD CONSTRAINT cms_page_revisions_published_is_stamped CHECK (
                    (publication_state = 'published' AND published_at IS NOT NULL)
                    OR (publication_state = 'scheduled' AND published_at IS NULL)
                    OR publication_state = 'superseded'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_page_revisions
                DROP CONSTRAINT cms_page_revisions_published_is_stamped,
                ADD CONSTRAINT cms_page_revisions_published_is_stamped CHECK (
                    (publication_state = 'scheduled') = (published_at IS NULL)
                );
        SQL);
    }
};
