<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who put a KYC file on a round when it was not the applicant.
 *
 * Staff may upload documents on behalf of a partner or a Supplier. A reviewer
 * deciding on that evidence has to be able to tell it did not come from the
 * applicant, so the file carries the member of staff who added it. Null means
 * the applicant uploaded it themselves, which is every file before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['kyc_documents', 'supplier_kyc_documents'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('uploaded_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['kyc_documents', 'supplier_kyc_documents'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('uploaded_by_staff_id');
            });
        }
    }
};
