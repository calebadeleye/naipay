<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exact per-instalment breakdown of an approved repayment.
 *
 * `repayments.allocated_*` holds the totals; this is what makes a reversal
 * exact rather than approximate — reversing a repayment subtracts precisely
 * these amounts back off the instalments they were applied to, which cannot
 * be reconstructed from aggregates alone once more than one repayment has
 * touched the same instalment over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repayment_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('repayment_id')->constrained('repayments')->cascadeOnDelete();
            $table->foreignId('loan_schedule_entry_id')->constrained('loan_schedule_entries')->restrictOnDelete();

            $table->decimal('principal_amount', 20, Money::SCALE)->default(0);
            $table->decimal('interest_amount', 20, Money::SCALE)->default(0);
            $table->decimal('fee_amount', 20, Money::SCALE)->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['repayment_id', 'loan_schedule_entry_id']);
            $table->index('loan_schedule_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repayment_allocations');
    }
};
