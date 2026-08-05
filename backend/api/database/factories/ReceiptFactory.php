<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Receipts\Models\Receipt;
use App\Domains\Repayments\Enums\RepaymentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
final class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $repayment = RepaymentFactory::new()->state(['status' => RepaymentStatus::Approved])->create();

        return [
            'receipt_number' => 'NPT-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'repayment_id' => $repayment->id,
            'merchant_id' => $repayment->merchant_id,
            'business_id' => $repayment->business_id,
            'loan_id' => $repayment->loan_id,
            'amount' => $repayment->amount,
            'issued_at' => now(),
        ];
    }
}
