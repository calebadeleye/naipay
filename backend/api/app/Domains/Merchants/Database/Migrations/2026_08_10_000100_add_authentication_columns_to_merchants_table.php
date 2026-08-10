<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant gains the ability to sign in to their own self-service portal.
 * `password` is nullable — a merchant exists (created by staff during
 * onboarding) before they have ever set one; `activated_at` distinguishes
 * "never activated the portal" from "has signed in before" independently of
 * whether a password happens to be set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->string('password')->nullable()->after('email');
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->index('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropColumn([
                'password',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'last_login_ip',
                'activated_at',
            ]);
        });
    }
};
