<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a bank statement, transcribed by an officer.
 *
 * Matched against a Repayment (a credit line — money the bank shows coming
 * in) or a Loan's disbursement (a debit line — money going out). `matched_to`
 * is resolved manually rather than through Eloquent's morph map, since
 * exactly two record types can ever appear there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            $table->date('statement_date');
            $table->string('description', 500)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->decimal('amount', 20, Money::SCALE);
            $table->string('direction', 10);

            $table->string('status', 20)->default('unmatched');

            $table->string('matched_to_type', 60)->nullable();
            $table->unsignedBigInteger('matched_to_id')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();

            $table->text('excluded_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();

            $table->index(['bank_reconciliation_id']);
            $table->index(['bank_account_id', 'statement_date']);
            $table->index(['matched_to_type', 'matched_to_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
