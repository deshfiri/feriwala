<?php

use App\Domain\Sourcing\Actions\ConvertSourcingGroupsToProductLinks;
use Illuminate\Database\Migrations\Migration;

/**
 * Carries what Product Sourcing Groups already declared over into direct Same
 * Product links, once, so allocation can stop reading groups without losing a
 * single existing match. Idempotent, and it never touches the groups
 * themselves: they are protected history.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ConvertSourcingGroupsToProductLinks::class)->handle();
    }

    public function down(): void
    {
        // Links are history once made; nothing to undo.
    }
};
