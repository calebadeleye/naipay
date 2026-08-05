<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manually recorded repayment against a loan.
 *
 * Recording and verifying a repayment never move money — they are an
 * officer reporting what the bank shows, and a second officer confirming it
 * against the evidence. Approval is the one step that allocates the amount
 * against the loan's schedule and posts the ledger entry; everything here
 * before that point is provisional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repayments', function (Blueprint $table): void {
            $table->id();

            $table->string('repayment_reference', 32)->unique();

            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('receiving_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            $table->decimal('amount', 20, Money::SCALE);
            $table->date('payment_date');
            $table->string('payment_method', 30);

            // Evidence a bank statement or teller slip carries, used to match
            // this repayment to the actual transfer and to detect duplicates.
            $table->string('sender_account_name', 200)->nullable();
            $table->string('sender_bank_name', 150)->nullable();
            $table->string('bank_reference', 100)->nullable();

            $table->text('notes')->nullable();

            $table->string('status', 20)->default('recorded');

            // --- Set at approval, once the amount is actually allocated -----------
            $table->decimal('allocated_fee', 20, Money::SCALE)->nullable();
            $table->decimal('allocated_interest', 20, Money::SCALE)->nullable();
            $table->decimal('allocated_principal', 20, Money::SCALE)->nullable();
            // Beyond what the loan owed: held against the merchant's own
            // account (naipay.allocation.surplus_bucket = 'excess') or parked
            // in suspense for an officer to assign ('unallocated').
            $table->decimal('allocated_excess', 20, Money::SCALE)->nullable();
            $table->decimal('allocated_unallocated', 20, Money::SCALE)->nullable();

            $table->foreignId('repayment_journal_transaction_id')->nullable()
                ->constrained('journal_transactions')->nullOnDelete();
            $table->foreignId('reversal_journal_transaction_id')->nullable()
                ->constrained('journal_transactions')->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->foreignId('verified_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('reversed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('loan_id');
            $table->index('merchant_id');
            $table->index(['branch_id', 'status']);
            $table->index('payment_date');
            // Duplicate detection: an exact bank reference match on the same
            // receiving account, within naipay.duplicate_detection's lookback
            // window.
            $table->index(['receiving_bank_account_id', 'bank_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repayments');
    }
};
