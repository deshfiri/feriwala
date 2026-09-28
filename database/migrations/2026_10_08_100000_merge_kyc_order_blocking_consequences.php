<?php

use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `block_wholesale_orders` becomes `block_new_orders` (§7.4).
 *
 * The consequence used to stop wholesale ordering alone, which left the
 * dropshipping channel open: a business we were unsure enough about to stop
 * buying from us could still take new orders through its own storefront. One
 * consequence now covers both, and {@see KycConsequence}
 * carries the single `BlockNewOrders` case.
 *
 * Renaming the case alone would not have been safe. `consequences` is a jsonb
 * array of raw strings and {@see KycSubmission::consequences()}
 * reads it with `tryFrom`, so a round still holding the old string would not
 * error — it would quietly drop the value and stop restricting a business
 * that had been told it was restricted. A silent release is the worst of the
 * available failures, so the stored values move too.
 *
 * Rewriting history is exactly what this does, and it is the right call here:
 * the value is an internal encoding of a live requirement, not a record of
 * something that happened. A round that said "no new wholesale orders"
 * yesterday says "no new orders" today, which is a widening of the same
 * restriction that §7.4 always allowed staff to choose.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('block_wholesale_orders', 'block_new_orders');
    }

    public function down(): void
    {
        $this->rewrite('block_new_orders', 'block_wholesale_orders');
    }

    /**
     * Swap one consequence string for another, in place, across every round
     * that carries it.
     *
     * Element-wise through `jsonb_array_elements_text` rather than a string
     * replace over the whole document: a substring rewrite would also corrupt
     * any future consequence whose name happened to contain this one.
     */
    protected function rewrite(string $from, string $to): void
    {
        // Positional bindings: `$from` is needed twice, and PDO's Postgres
        // driver will not let a named placeholder appear more than once.
        DB::statement(<<<'SQL'
            UPDATE kyc_submissions
            SET consequences = rewritten.value
            FROM (
                SELECT
                    id,
                    jsonb_agg(
                        CASE WHEN element = ? THEN to_jsonb(?::text)
                             ELSE to_jsonb(element)
                        END
                        ORDER BY ordinality
                    ) AS value
                FROM kyc_submissions,
                     LATERAL jsonb_array_elements_text(consequences)
                             WITH ORDINALITY AS t(element, ordinality)
                WHERE consequences IS NOT NULL
                GROUP BY id
            ) AS rewritten
            WHERE kyc_submissions.id = rewritten.id
              AND kyc_submissions.consequences @> to_jsonb(ARRAY[?::text])
        SQL, [$from, $to, $from]);
    }
};
