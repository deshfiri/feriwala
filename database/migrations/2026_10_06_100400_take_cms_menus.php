<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public-site navigation menus — header, footer, legal (§4, §34).
 *
 * An item points at either an internal named route or a safe external URL,
 * never both and never neither — enforced by the CHECK below and again by
 * App\Domain\Cms\Rules\SafeMenuUrl at the application boundary, which is
 * where the scheme allow-list actually lives (a CHECK constraint cannot
 * express "reject javascript: and data: schemes" without duplicating that
 * list in SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menus', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('location', 16)->unique();

            $table->timestamps();
        });

        Schema::create('cms_menu_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cms_menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cms_menu_items')->cascadeOnDelete();

            $table->string('label_en');
            $table->string('label_bn')->nullable();

            $table->string('route_name')->nullable();
            $table->string('external_url')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->string('link_target', 8)->default('self');

            $table->timestamps();

            $table->index(['cms_menu_id', 'parent_id', 'sort_order']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_menus
                ADD CONSTRAINT cms_menus_location_known CHECK (location IN ('header', 'footer', 'legal'));

            ALTER TABLE cms_menu_items
                ADD CONSTRAINT cms_menu_items_label_present CHECK (length(btrim(label_en)) > 0),
                ADD CONSTRAINT cms_menu_items_target_known CHECK (link_target IN ('self', 'blank')),
                ADD CONSTRAINT cms_menu_items_exactly_one_destination CHECK (
                    (route_name IS NOT NULL) <> (external_url IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_menu_items');
        Schema::dropIfExists('cms_menus');
    }
};
