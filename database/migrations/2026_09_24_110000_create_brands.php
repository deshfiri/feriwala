<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product brands (§11.3).
 *
 * Flat, unlike categories. A brand is a fact about who made something rather
 * than a place in an arrangement, and nesting it would invite a hierarchy nobody
 * asked for — "Samsung › Galaxy" is a product line, which is what categories and
 * variations are for.
 *
 * Two brands may not share a name, case ignored. A catalogue holding "Samsung"
 * and "samsung" has somebody's products split across two brands that filter
 * separately, and the storefront shows half a range under each. The index is
 * functional rather than a plain unique because Postgres compares strings
 * exactly, and it is the database that has to hold this when two administrators
 * add the same brand at the same moment — validation only catches the ordinary
 * case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Public URLs carry this, never the id (§34.3).
            $table->string('slug')->unique();
            $table->string('name');

            $table->text('description')->nullable();

            /*
             * The logo lives on the public disk: a brand mark is meant to be
             * rendered by partner storefronts, so putting it behind an
             * authorisation check would mean proxying every request for an image
             * that is already public by intent.
             */
            $table->string('logo_path')->nullable();
            $table->string('logo_alt')->nullable();

            // Position in a brand list somebody arranged. Reordering lands with
            // the rest of the arrangement work (P3-14).
            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        DB::statement('CREATE UNIQUE INDEX brands_name_unique ON brands (LOWER(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
