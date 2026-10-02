<?php

use App\Integrations\Courier\CourierManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courier providers as a seeded reference table (Advanced Order Management
 * batch, Commit 5; D8).
 *
 * Only `manual` is enabled and has a driver behind it in this batch —
 * `steadfast` and `pathao` exist as disabled, credential-less rows so the
 * {@see CourierManager} and the UI can name them
 * ("requires provider credentials") without inventing API protocol code
 * nobody can test against. `credentials` stays null for every row this batch
 * seeds; it exists for whichever later batch adds real provider credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 80);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('supports_api')->default(false);
            $table->boolean('supports_webhook')->default(false);
            $table->boolean('supports_label')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('courier_providers')->insert([
            [
                'code' => 'manual',
                'name' => 'Manual',
                'is_enabled' => true,
                'supports_api' => false,
                'supports_webhook' => false,
                'supports_label' => false,
                'credentials' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'steadfast',
                'name' => 'Steadfast Courier',
                'is_enabled' => false,
                'supports_api' => true,
                'supports_webhook' => true,
                'supports_label' => true,
                'credentials' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'pathao',
                'name' => 'Pathao Courier',
                'is_enabled' => false,
                'supports_api' => true,
                'supports_webhook' => true,
                'supports_label' => true,
                'credentials' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_providers');
    }
};
