<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the applicant's dropdown for a group is called (D30).
 *
 * A group that asks for "any one" (or "at least N") option is drawn as a choice:
 * a select, and only the chosen option's evidence slots below it. The select needs
 * a label that suits what it chooses between — "Select identification document"
 * for an identity group is right and the same words on an address group are not —
 * so staff name it per group. Nullable: with nothing set the form says "Select one
 * option". Published versions made before this existed carry no label and read the
 * same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_batch_groups', function (Blueprint $table) {
            $table->string('selector_label', 120)->nullable()->after('description_bn');
            $table->string('selector_label_bn', 120)->nullable()->after('selector_label');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_batch_groups', function (Blueprint $table) {
            $table->dropColumn(['selector_label', 'selector_label_bn']);
        });
    }
};
