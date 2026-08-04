<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds device and network provenance to issued API tokens.
 *
 * Without this a token is an opaque row, and "sign out my other sessions" is a
 * meaningless option — an operator reviewing their active sessions needs to
 * recognise which is the workstation in front of them and which is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Human-readable summary derived from the user agent, e.g.
            // "Chrome on Windows" — what the operator actually recognises.
            $table->string('device_name', 120)->nullable()->after('name');
            $table->string('ip_address', 45)->nullable()->after('device_name');
            $table->text('user_agent')->nullable()->after('ip_address');

            // Absolute session expiry, independent of idle timeout.
            $table->timestamp('last_activity_at')->nullable()->after('last_used_at');

            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn(['device_name', 'ip_address', 'user_agent', 'last_activity_at']);
        });
    }
};
