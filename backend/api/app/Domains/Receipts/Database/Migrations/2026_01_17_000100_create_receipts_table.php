<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt issued for an approved repayment.
 *
 * Generated automatically the moment a repayment is approved — see
 * RepaymentService::approve() — never a separate manual step, so an approved
 * repayment can never exist without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();

            $table->string('receipt_number', 32)->unique();

            $table->foreignId('repayment_id')->unique()->constrained('repayments')->restrictOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();

            $table->decimal('amount', 20, Money::SCALE);
            $table->timestamp('issued_at');

            $table->timestamps();

            $table->index('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
