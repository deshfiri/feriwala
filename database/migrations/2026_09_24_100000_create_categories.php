<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product categories (§11.3).
 *
 * Nested through `parent_id`, which is what lets §11.1's "Category" and
 * "Subcategory" both be answered without storing them twice. A product points at
 * one category — the most specific one — and its parent is read from the tree.
 * Two columns holding a category and a subcategory would be two answers to one
 * question, and they disagree the first time somebody moves a subcategory.
 *
 * `parent_id` restricts rather than cascades. A category with children
 * disappearing would take a branch of the catalogue with it, and §11.3 gives
 * administrators enable/disable for exactly that — a category that should stop
 * being used is switched off, not deleted.
 *
 * The SEO columns are here because §11.3 asks for category SEO on partner
 * websites, and a storefront rendering a category page needs a title and a
 * description that somebody chose rather than one derived from the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Public URLs carry this, never the id (§34.3).
            $table->string('slug')->unique();
            $table->string('name');

            /*
             * Self-referencing, restricted on delete. A branch of the catalogue
             * is not something to lose by removing one row.
             */
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->restrictOnDelete();

            $table->text('description')->nullable();

            // Public imagery: category tiles on partner storefronts (§11.3).
            $table->string('image_path')->nullable();
            $table->string('image_alt')->nullable();

            // SEO for partner websites (§11.3, §34.3).
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();

            /*
             * Position among siblings, not globally. Reordering one branch must
             * not renumber another.
             */
            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
