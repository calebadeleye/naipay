<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kept separate from Laravel's own `password_reset_tokens` (staff's table)
 * rather than shared: the two are keyed only by email with no guard
 * discriminator, and a staff member and a merchant have no reason to have
 * their reset flows coupled just because they might share an email address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_password_reset_tokens');
    }
};
