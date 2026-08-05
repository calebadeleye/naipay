<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Naipay's own designated bank accounts — where merchant repayments are
 * collected and where loan funds are disbursed from.
 *
 * Distinct from `merchant_accounts` (Phase 5), which are the internal wallet
 * each merchant is given. These are real accounts Naipay holds at real banks,
 * and every one of them is a point external money actually moves through, so
 * changes to this table are maker-checked and audited without exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();

            $table->string('bank_name', 150);
            $table->string('bank_code', 10)->nullable();
            $table->string('account_name', 200);
            $table->char('account_number', 10);
            $table->string('branch_name', 150)->nullable();
            $table->string('currency', 3)->default('NGN');

            $table->string('account_purpose', 40);

            /*
             * At most one default per purpose. Enforced in the service layer
             * rather than a unique index, because "at most one true value
             * within a group" is not something a plain unique constraint can
             * express, and a partial/filtered unique index is not portable
             * across the MySQL versions this runs on.
             */
            $table->boolean('is_default_collection_account')->default(false);
            $table->boolean('is_default_disbursement_account')->default(false);

            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // Closed, never deleted: every repayment and disbursement on
            // record names one of these, permanently.
            $table->softDeletes();

            $table->unique(['bank_name', 'account_number']);
            $table->index(['account_purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
