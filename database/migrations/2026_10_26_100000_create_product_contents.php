<?php

use App\Domain\Storage\Models\StoredFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product updates an administrator publishes from the product's own admin
 * page: a title, an optional body, and at most one attachment (an image or a
 * file, tracked through the shared {@see StoredFile} table rather than a
 * column of its own).
 *
 * Published immediately, with no draft/schedule/unpublish workflow — nothing
 * in the request asked for one, and the three-way state machine every other
 * lifecycle column here earns is not warranted for a one-way announcement
 * feed. Every partner on an operating account may read these; nothing here
 * decides who may, since that is the existing `business.activated` gate on
 * the catalogue route this is rendered from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_contents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 255);
            $table->text('body')->nullable();

            $table->timestamp('published_at');

            $table->timestamps();

            $table->index(['product_id', 'published_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_contents
                ADD CONSTRAINT product_contents_title_present CHECK (length(btrim(title)) > 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_contents');
    }
};
