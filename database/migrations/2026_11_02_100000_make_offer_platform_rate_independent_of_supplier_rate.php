<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Supplier Base Price (`supplier_rate`) and the offer's platform rate are
 * independent figures: a Supplier may be paid more than a Product resells for,
 * and staff are warned of the lost margin rather than stopped.
 *
 * Removes the CHECK rules that forced `platform_rate >= supplier_rate` wherever
 * the database held them — on offers, on their price history and on the
 * order-line supplier snapshot — keeping every other part of those constraints
 * (non-negative figures, margin = platform rate − supplier rate). They are found
 * by what they say rather than by name, so a constraint renamed by an earlier
 * conversion is still caught. Nothing existing is changed or removed: every
 * historical row already satisfies the weaker rule.
 */
return new class extends Migration
{
    private const TABLES = ['supplier_offers', 'supplier_offer_price_changes', 'order_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $constraints = DB::select(
                "SELECT c.conname, pg_get_constraintdef(c.oid) AS definition
                   FROM pg_constraint c
                   JOIN pg_class t ON t.oid = c.conrelid
                   JOIN pg_namespace n ON n.oid = t.relnamespace
                  WHERE c.contype = 'c' AND t.relname = ? AND n.nspname = current_schema()",
                [$table],
            );

            foreach ($constraints as $constraint) {
                $core = 'platform_rate\w*\s*>=\s*supplier_rate\w*';
                $pattern = '/'.$core.'/i';

                if (! preg_match($pattern, $constraint->definition)) {
                    continue;
                }

                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$constraint->conname}\"");

                // A rule that said only this is gone; one that said more keeps
                // the rest of what it said.
                $rest = preg_replace('/\s*AND\s*\(*\s*'.$core.'\s*\)*/i', '', $constraint->definition, 1, $count);

                if ($count === 1 && ! preg_match($pattern, $rest)) {
                    DB::statement("ALTER TABLE {$table} ADD CONSTRAINT \"{$constraint->conname}\" {$rest}");
                }
            }
        }
    }

    public function down(): void
    {
        // The rule was a business decision that no longer holds; it is not
        // restored, because rows that rely on its absence may exist by then.
    }
};
