<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether a reauthentication was backed by a two-factor code, not
 * just a password.
 *
 * `reauthenticated_at` alone cannot tell MakerCheckerGuard how strong that
 * proof was. Some operations (naipay.security.reauthentication_two_factor_optional_operations)
 * accept a password-only reauthentication; everything else in
 * reauthentication_required_operations still needs a two-factor-backed one
 * when the account has two-factor enabled. Without this column, a
 * password-only reauthentication done for the lenient operation would also
 * satisfy the strict ones for the rest of the reauthentication window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->boolean('reauthenticated_with_two_factor')->default(false)->after('reauthenticated_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('reauthenticated_with_two_factor');
        });
    }
};
