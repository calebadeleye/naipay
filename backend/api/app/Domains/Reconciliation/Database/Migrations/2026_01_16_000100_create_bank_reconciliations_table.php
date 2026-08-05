<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reconciliation exercise: one bank account, one statement period.
 *
 * Naipay has no direct bank feed in this release, so the officer's own
 * transcription of the statement's opening and closing balance is what
 * every line here is checked against — see BankReconciliationStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->decimal('statement_opening_balance', 20, Money::SCALE);
            $table->decimal('statement_closing_balance', 20, Money::SCALE);

            $table->string('status', 20)->default('in_progress');
            $table->text('notes')->nullable();

            $table->foreignId('prepared_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bank_account_id', 'period_start', 'period_end'], 'bank_reconciliations_account_period_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
