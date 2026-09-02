<?php

use App\Support\Rules\RuleResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed, admin-editable settings.
 *
 * A large share of the specification is "the admin can configure X" — fees,
 * deadlines, thresholds, toggles. Those belong here. Anything with scope
 * precedence, effective dates, or history (commission, deposit, withdrawal,
 * referral rules) belongs in its own rule table instead, resolved by
 * {@see RuleResolver} — this table has no notion of "which
 * one wins".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('group', 64)->index();

            // Stored as text and cast on read, so one column serves booleans,
            // integers, money, and JSON without a nullable column per type.
            $table->text('value')->nullable();
            $table->string('type', 24)->default('string');

            // Encrypted at rest for gateway, SMS, and courier credentials
            // (§26.4, §36). The flag tells the accessor whether to decrypt.
            $table->boolean('is_encrypted')->default(false);

            // Settings a partner may read; the rest are admin-only. Marked
            // explicitly rather than inferred, so exposing one is a decision.
            $table->boolean('is_public')->default(false);

            $table->string('label')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
