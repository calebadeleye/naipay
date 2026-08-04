<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal staff accounts — the only identities that can authenticate in the
 * first release.
 *
 * Merchants will authenticate against a separate table and guard when the
 * merchant portal arrives, so that a merchant credential can never be used
 * against an administrative endpoint no matter how routing evolves.
 *
 * Organisational fields (branch, approval limit, department) are added by the
 * Branches and Staff phase; this migration covers identity and authentication
 * only, so the phase remains independently deployable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();

            $table->string('staff_number', 32)->unique();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);

            $table->string('email', 190)->unique();
            // Optional alternative credential: branch staff frequently sign in
            // with a short username rather than a full email address.
            $table->string('username', 60)->nullable()->unique();
            $table->string('phone', 20)->nullable();
            $table->string('job_title', 120)->nullable();

            $table->string('password');

            // Forces a password change at next sign-in — set when an account is
            // created or an administrator resets it.
            $table->boolean('must_change_password')->default(true);
            $table->timestamp('password_changed_at')->nullable();

            $table->string('status', 32)->default('pending_activation');

            /*
             * Two-factor authentication.
             *
             * The secret and recovery codes are encrypted at rest via the model's
             * casts. `two_factor_confirmed_at` is what actually enables 2FA: a
             * secret that has been issued but never verified must not lock an
             * operator out of their own account.
             */
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            /*
             * Lockout state.
             *
             * Counted per account, distinct from the per-IP throttle in front of
             * the endpoint: this stops an attacker working through one operator's
             * password, the throttle stops them spraying many accounts.
             */
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();

            // Staff records are never hard-deleted: they are referenced by loans
            // they approved, repayments they recorded and audit entries that must
            // remain attributable for the life of the record.
            $table->softDeletes();

            $table->index('status');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
