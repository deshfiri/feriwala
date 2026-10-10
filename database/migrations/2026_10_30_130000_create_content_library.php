<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Content Library: content an administrator writes once and releases to
 * one or more Products.
 *
 * Separate from a Product's own updates (`product_contents`), which belong to
 * one Product and carry one attachment. A library item is an ordered list of
 * blocks -- text, image, video, link -- held as JSON, so what is published is
 * structured data the page renders itself and never HTML to be trusted.
 * Images and videos are managed files (`stored_files`) referenced by public id
 * from inside a block.
 *
 * Released immediately and removed with a soft delete: a removed item stops
 * showing to partners but stays as the record of what was published, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_library_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('title', 255);
            $table->jsonb('blocks');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['published_at']);
        });

        Schema::create('content_library_item_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_library_item_id')->constrained('content_library_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['content_library_item_id', 'product_id']);
            $table->index('product_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE content_library_items
                ADD CONSTRAINT content_library_items_title_present CHECK (length(btrim(title)) > 0),
                ADD CONSTRAINT content_library_items_blocks_is_list CHECK (jsonb_typeof(blocks) = 'array');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_library_item_product');
        Schema::dropIfExists('content_library_items');
    }
};
