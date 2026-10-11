<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Content Library sets no length limit on what is written in it, so a title
 * is free text rather than a 255-character column.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE content_library_items ALTER COLUMN title TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content_library_items ALTER COLUMN title TYPE varchar(255)');
    }
};
