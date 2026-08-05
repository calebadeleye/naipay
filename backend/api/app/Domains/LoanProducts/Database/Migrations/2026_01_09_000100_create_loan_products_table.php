<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan products — the packages a merchant chooses between.
 *
 * Naipay's products are organised around repayment frequency, because money
 * arriving every working day is a materially different risk from money arriving
 * once a month, and is priced accordingly.
 *
 * Everything about a product is data. Rates, tenor ranges and fees are edited
 * by an authorised administrator, and a new package is added by inserting a
 * row — no deployment, and no calculation logic anywhere but the calculator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();

            // --- Amount and term ------------------------------------------
            $table->decimal('minimum_amount', 20, Money::SCALE);
            $table->decimal('maximum_amount', 20, Money::SCALE);

            /*
             * Tenor is a range, not a fixed number. The officer sets the actual
             * term per loan after agreeing the package with the merchant, so
             * the product states only what is permitted.
             */
            $table->unsignedInteger('minimum_tenor');
            $table->unsignedInteger('maximum_tenor');
            $table->string('tenor_unit', 20);
            $table->unsignedInteger('default_tenor')->nullable();

            // --- Pricing ---------------------------------------------------
            $table->string('interest_method', 40);

            /*
             * Stored as a percentage the way people state it — 20.0000 means
             * 20% — with four decimal places so a rate like 1.6667% survives.
             * Never a float: the rate multiplies principal, and a binary
             * approximation would change what a merchant owes.
             */
            $table->decimal('interest_rate', 9, 4);

            // For flat rates this is informational: the rate applies once over
            // the whole term regardless of period.
            $table->string('interest_period', 20)->default('per_loan');

            $table->string('repayment_frequency', 20);

            // --- Fees and penalties ----------------------------------------
            $table->string('processing_fee_type', 20)->default('none');
            $table->decimal('processing_fee_value', 20, Money::SCALE)->default(0);

            $table->string('insurance_fee_type', 20)->default('none');
            $table->decimal('insurance_fee_value', 20, Money::SCALE)->default(0);

            $table->string('late_payment_penalty_type', 20)->default('none');
            $table->decimal('late_payment_penalty_value', 20, Money::SCALE)->default(0);

            $table->unsignedInteger('grace_period_days')->default(0);

            // --- Requirements ------------------------------------------------
            $table->boolean('requires_guarantor')->default(false);
            $table->unsignedTinyInteger('minimum_guarantors')->default(0);
            $table->boolean('requires_collateral')->default(false);

            $table->string('status', 20)->default('active');
            $table->unsignedInteger('display_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();

            $table->timestamps();

            // Products are retired, never deleted: loans booked under a product
            // must keep pointing at the terms they were sold on.
            $table->softDeletes();

            $table->index(['status', 'display_order']);
            $table->index('repayment_frequency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_products');
    }
};
