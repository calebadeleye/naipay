<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row of a loan's repayment schedule, as built by LoanCalculator at
 * disbursement.
 *
 * An operational record for collections and due-date views, not itself
 * financial truth — the ledger is. The `_paid` columns stay zero and `status`
 * stays Pending until Phase 9's repayment allocation writes into them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_schedule_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->date('due_date');

            $table->decimal('opening_principal', 20, Money::SCALE);
            $table->decimal('principal_due', 20, Money::SCALE);
            $table->decimal('interest_due', 20, Money::SCALE);
            $table->decimal('fee_due', 20, Money::SCALE)->default(0);

            $table->decimal('principal_paid', 20, Money::SCALE)->default(0);
            $table->decimal('interest_paid', 20, Money::SCALE)->default(0);
            $table->decimal('fee_paid', 20, Money::SCALE)->default(0);

            $table->string('status', 20)->default('pending');

            $table->timestamps();

            $table->unique(['loan_id', 'installment_number']);
            $table->index(['loan_id', 'due_date']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_schedule_entries');
    }
};
