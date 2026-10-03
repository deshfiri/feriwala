<?php

use App\Domain\Account\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table): void {
            // Same plain-string-with-default style as `status` on this table —
            // no native Postgres enum type, no locked-columns trigger: an
            // administrator may change this back and forth freely.
            $table->string('account_type', 20)
                ->default(AccountType::Conditional->value)
                ->after('status');

            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('business_accounts', function (Blueprint $table): void {
            $table->dropIndex(['account_type']);
            $table->dropColumn('account_type');
        });
    }
};
