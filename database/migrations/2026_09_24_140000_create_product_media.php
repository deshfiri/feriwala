<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product images and videos (§11.1).
 *
 * The shape the storefront contract already promises (§5.1 of the contract):
 * a URL, alt text, a position and a type. Stored as uploaded files on the
 * public disk — catalogue media is meant to be rendered by every partner
 * storefront — with the MIME type and size the server read from the bytes,
 * not the ones the browser claimed.
 *
 * Restricted on the product: a product is removed only as a draft, and its
 * media are removed explicitly first rather than swept away by a cascade
 * nobody saw. A variation's link is nulled when the variation goes, because
 * the picture still shows the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Optional: the picture of the navy one specifically.
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->string('type', 10);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            // Read from an image's own header, so a storefront can reserve the
            // space before it loads. Null for video.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('alt_text', 255)->nullable();

            // 1-based, matching the storefront contract's `position`.
            $table->unsignedInteger('position');

            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_media
                ADD CONSTRAINT product_media_type_known CHECK (type IN ('image', 'video')),
                ADD CONSTRAINT product_media_size_positive CHECK (size_bytes > 0),
                ADD CONSTRAINT product_media_position_positive CHECK (position >= 1);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
