<?php

declare(strict_types=1);

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan applications — the workflow between an approved merchant/business and
 * an eventual loan.
 *
 * A merchant and a business are both referenced: the credit relationship
 * belongs to the merchant, but the amount they can plausibly repay is
 * assessed against a specific trade, and a merchant may run more than one.
 *
 * The requested and approved figures are kept separate. A credit manager may
 * approve less than was asked for, and the interest rate is snapshotted at
 * approval so a later change to the product's pricing does not rewrite what
 * was promised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table): void {
            $table->id();

            $table->string('application_number', 32)->unique();

            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('loan_product_id')->constrained('loan_products')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->decimal('requested_amount', 20, Money::SCALE);
            $table->unsignedInteger('requested_tenor');
            $table->text('purpose')->nullable();

            $table->string('status', 30)->default('draft');

            // --- Assessment and recommendation ---------------------------------
            $table->text('assessment_notes')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();

            $table->text('recommendation_notes')->nullable();
            $table->foreignId('recommended_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('recommended_at')->nullable();

            // --- Decision --------------------------------------------------------
            $table->decimal('approved_amount', 20, Money::SCALE)->nullable();
            $table->unsignedInteger('approved_tenor')->nullable();
            $table->decimal('approved_interest_rate', 9, 4)->nullable();
            $table->text('decision_reason')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            $table->text('withdrawal_reason')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();

            // --- Custody -----------------------------------------------------------
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            // A submitted application left undecided this long is swept to
            // Expired rather than sitting in the queue indefinitely.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // Referenced by an eventual loan; closed, never removed.
            $table->softDeletes();

            $table->index('status');
            $table->index(['branch_id', 'status']);
            $table->index('merchant_id');
            $table->index('business_id');
            $table->index('loan_product_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};
