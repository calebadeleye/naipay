<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External investors: a separate authenticatable identity from Staff, so an
 * investor credential can never satisfy an administrative endpoint (see
 * config/auth.php's `investor` guard). An investor is read-only by design —
 * there is no permission system here, only a single dashboard to view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investors', function (Blueprint $table): void {
            $table->id();

            $table->string('investor_number', 32)->unique();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('phone', 20)->nullable();

            $table->string('password');
            $table->string('status', 32)->default('active');

            // Mirrors Staff's lockout counters: the only defence this guard
            // needs against credential guessing, since there is no 2FA here.
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
