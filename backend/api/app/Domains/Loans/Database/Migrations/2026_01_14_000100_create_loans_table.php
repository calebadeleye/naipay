<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A loan: the credit facility that exists once a loan application is
 * approved, before and after money actually moves.
 *
 * Terms are snapshotted from the loan product and the approved application at
 * creation time, so a later change to the product's pricing never rewrites a
 * contract already in force. Outstanding balances and the schedule stay null
 * until disbursement fixes the disbursement date and the calculator can build
 * a real schedule; nothing here is a source of financial truth on its own —
 * every balance-changing event is also a ledger posting, per
 * LedgerPostingService's guarantees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table): void {
            $table->id();

            $table->string('loan_reference', 32)->unique();

            $table->foreignId('loan_application_id')->constrained('loan_applications')->restrictOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('loan_product_id')->constrained('loan_products')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // --- Terms, snapshotted at creation -----------------------------------
            $table->decimal('principal_amount', 20, Money::SCALE);
            $table->string('interest_method', 30);
            $table->decimal('interest_rate', 9, 4);
            $table->string('repayment_frequency', 20);
            $table->unsignedInteger('tenor');
            $table->unsignedInteger('grace_period_days')->default(0);

            // --- Fixed once disbursed ----------------------------------------------
            $table->decimal('total_interest', 20, Money::SCALE)->nullable();
            $table->decimal('total_fees', 20, Money::SCALE)->nullable();
            $table->decimal('total_payable', 20, Money::SCALE)->nullable();

            // Cached running balances. A reconciliation report should always be
            // able to re-derive these from journal_entries; see AccountBalance's
            // own docblock for why a cache exists alongside the ledger at all.
            $table->decimal('outstanding_principal', 20, Money::SCALE)->nullable();
            $table->decimal('outstanding_interest', 20, Money::SCALE)->nullable();
            $table->decimal('outstanding_fees', 20, Money::SCALE)->nullable();

            $table->foreignId('disbursement_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->date('disbursement_date')->nullable();
            $table->date('first_repayment_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->foreignId('disbursement_journal_transaction_id')->nullable()
                ->constrained('journal_transactions')->nullOnDelete();

            $table->string('status', 30)->default('pending_approval');

            // --- Custody and sign-off -----------------------------------------------
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('written_off_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('written_off_at')->nullable();
            $table->text('write_off_reason')->nullable();

            $table->timestamps();

            // Closed or written off, never removed: every disbursement and
            // repayment on record names one of these, permanently.
            $table->softDeletes();

            $table->index('status');
            $table->index(['branch_id', 'status']);
            $table->index('merchant_id');
            $table->index('business_id');
            $table->index('loan_product_id');
            $table->index('disbursement_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
