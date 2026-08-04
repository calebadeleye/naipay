<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every authentication attempt, successful or not.
 *
 * Kept separate from the general audit log because it is written on the
 * unauthenticated path — there is no actor to attribute it to yet, and an
 * attempt against a non-existent account still needs recording.
 *
 * This is the evidence behind account lockout, the login history an operator
 * reviews for unrecognised access, and the compliance question of who signed
 * in to a financial system and from where.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->id();

            // Null when the identifier matched no account. The attempt is still
            // recorded: a run of these is exactly the enumeration pattern worth
            // seeing.
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();

            // The email or username as supplied. Never a password, and never
            // normalised away — the exact value attempted is the useful detail.
            $table->string('identifier', 190);

            $table->boolean('successful');

            // Why a failure was refused: invalid_credentials, account_locked,
            // account_disabled, invalid_two_factor, expired_two_factor.
            $table->string('failure_reason', 64)->nullable();

            // IPv6 needs 45 characters.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_name', 120)->nullable();

            // Ties the attempt to everything else that happened in the request.
            $table->uuid('correlation_id')->nullable();

            $table->timestamp('attempted_at');

            // Lockout counts recent failures for one account; the login history
            // screen reads the same rows newest-first.
            $table->index(['staff_id', 'attempted_at']);
            $table->index(['identifier', 'attempted_at']);
            $table->index(['ip_address', 'attempted_at']);
            $table->index('successful');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
