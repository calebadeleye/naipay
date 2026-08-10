<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records why and by whom an investor account was suspended, mirroring the
 * equivalent columns on `staff`. Without these the reason would live only in
 * the audit trail, which is fine for a legal record but not for an
 * administrator glancing at the account itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investors', function (Blueprint $table): void {
            $table->text('suspension_reason')->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['suspension_reason', 'suspended_at']);
        });
    }
};
