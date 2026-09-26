<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public-site 301/302 redirects (§34.1).
 *
 * `from_path` and `to_path` are both site-relative paths, never absolute
 * URLs — an open redirect needs an absolute destination the app does not
 * control, so the column simply never holds one. Loop and chain prevention
 * (a redirect must not point at another redirect's `from_path`, including
 * itself) is enforced in App\Domain\Cms\Actions\ManageRedirect, checked
 * fresh against current rows rather than in a CHECK constraint, since it is
 * a property of the *set* of rows, not any one row in isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_redirects', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('from_path')->unique();
            $table->string('to_path');
            $table->unsignedSmallInteger('status_code')->default(301);

            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_redirects
                ADD CONSTRAINT cms_redirects_status_code_known CHECK (status_code IN (301, 302, 307, 308)),
                ADD CONSTRAINT cms_redirects_from_is_relative CHECK (
                    from_path LIKE '/%' AND from_path NOT LIKE '//%' AND from_path !~ '^/[a-zA-Z][a-zA-Z0-9+.-]*:'
                ),
                ADD CONSTRAINT cms_redirects_to_is_relative CHECK (
                    to_path LIKE '/%' AND to_path NOT LIKE '//%' AND to_path !~ '^/[a-zA-Z][a-zA-Z0-9+.-]*:'
                ),
                ADD CONSTRAINT cms_redirects_not_self CHECK (from_path <> to_path);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_redirects');
    }
};
