<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Once carried Product Sourcing Groups over into Same Product links. Those links
 * are retired (see the migration that drops them), so there is nothing left to
 * convert; kept as an empty step so migration history stays intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Retired: Same Product links no longer exist.
    }

    public function down(): void
    {
        // Nothing to undo.
    }
};
