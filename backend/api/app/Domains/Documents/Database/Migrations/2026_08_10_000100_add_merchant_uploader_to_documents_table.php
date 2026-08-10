<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document may now be uploaded by the merchant it belongs to, through the
 * self-service portal, rather than only by staff on their behalf. Kept as a
 * separate nullable column rather than repurposing `uploaded_by` — that
 * column is FK-constrained to `staff` and changing its target would weaken
 * the guarantee for every existing staff-uploaded document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('uploaded_by_merchant_id')->nullable()->after('uploaded_by')->constrained('merchants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_by_merchant_id');
        });
    }
};
