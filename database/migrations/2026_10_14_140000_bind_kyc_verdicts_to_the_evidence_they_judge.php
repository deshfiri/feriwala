<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A verdict names the evidence it judged (D30).
 *
 * Until now a verdict was matched to a slot's current evidence by time: it applied
 * if it was written at or after the evidence. Timestamps in these tables have a
 * resolution of one second, so a reviewer sending a file back and an applicant
 * replacing it within the same second left the *new* file looking already
 * rejected. Naming the evidence removes the ambiguity: a document is identified by
 * its public id, a typed value by its row.
 *
 * Nullable, because verdicts written before this column existed have nothing to
 * name; those keep being matched by time. The tables stay append-only — this only
 * adds a column, and nothing is updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_evidence_reviews', function (Blueprint $table) {
            $table->string('evidence_ref', 64)->nullable()->after('slot_key');
        });

        Schema::table('supplier_kyc_evidence_reviews', function (Blueprint $table) {
            $table->string('evidence_ref', 64)->nullable()->after('slot_key');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_kyc_evidence_reviews', function (Blueprint $table) {
            $table->dropColumn('evidence_ref');
        });

        Schema::table('kyc_evidence_reviews', function (Blueprint $table) {
            $table->dropColumn('evidence_ref');
        });
    }
};
