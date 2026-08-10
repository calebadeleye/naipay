<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail gains a second possible actor type: a merchant, acting
 * through their own self-service portal (applying for a loan, uploading a
 * document, editing their profile), rather than a staff member acting on
 * their behalf. `staff_id` and `merchant_id` are mutually exclusive — an
 * entry has at most one actor — and `actor_type` makes which one applies
 * explicit rather than inferred from which foreign key happens to be set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('merchant_id')->nullable()->after('staff_id')->constrained('merchants')->nullOnDelete();
            $table->string('actor_type', 20)->nullable()->after('actor_roles');

            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merchant_id');
            $table->dropColumn('actor_type');
        });
    }
};
