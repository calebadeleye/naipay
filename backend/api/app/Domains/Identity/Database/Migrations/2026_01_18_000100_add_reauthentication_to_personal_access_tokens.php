<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the last time a token's holder proved their password (and 2FA code,
 * if enabled) again, independent of the session itself still being valid.
 *
 * A handful of operations — disbursement, a repayment reversal, a write-off,
 * a bank account change, a role change — are named in
 * naipay.security.reauthentication_required_operations specifically because
 * an unattended, already-unlocked workstation is enough to reach them
 * otherwise. See MakerCheckerGuard::requiresReauthentication().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->timestamp('reauthenticated_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('reauthenticated_at');
        });
    }
};
