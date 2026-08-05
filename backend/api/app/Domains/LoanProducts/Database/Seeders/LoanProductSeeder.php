<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Database\Seeders;

use App\Domains\LoanProducts\Enums\FeeType;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\LoanProducts\Enums\TenorUnit;
use App\Domains\LoanProducts\Models\LoanProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Naipay's opening loan packages.
 *
 * Three products, distinguished by how often the merchant repays:
 *
 *   Daily    20% flat  — borrow ₦100,000, repay ₦120,000
 *   Weekly   40% flat  — borrow ₦1,000,000, repay ₦1,400,000
 *   Monthly  40% flat  — priced as weekly
 *
 * Daily collection is cheaper because the money comes back every working day,
 * which is a materially lower risk than waiting a month for it.
 *
 * The rate is flat: charged once on the amount borrowed, unchanged by how long
 * the loan runs. That is what lets the tenor be agreed per loan rather than
 * fixed by the product.
 *
 * Seeded once. A reseed never overwrites a rate or a limit an administrator has
 * since changed — pricing is their decision, and a deploy must not quietly
 * reverse it. New packages are added here or through the administration screen.
 */
final class LoanProductSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->upsert([
                'code' => 'NPD-DAILY',
                'name' => 'Daily Repayment',
                'description' => 'Repaid every working day. Weekends are not collection days, so instalments fall Monday to Friday only.',
                'minimum_amount' => '10000.00',
                'maximum_amount' => '5000000.00',
                // Set per loan by the officer, within these bounds.
                'minimum_tenor' => 10,
                'maximum_tenor' => 120,
                'default_tenor' => 20,
                'tenor_unit' => TenorUnit::Days,
                'interest_method' => InterestMethod::Flat,
                'interest_rate' => '20.0000',
                'repayment_frequency' => RepaymentFrequency::Daily,
                'display_order' => 10,
            ]);

            $this->upsert([
                'code' => 'NPD-WEEKLY',
                'name' => 'Weekly Repayment',
                'description' => 'Repaid once a week.',
                'minimum_amount' => '20000.00',
                'maximum_amount' => '10000000.00',
                'minimum_tenor' => 4,
                'maximum_tenor' => 52,
                'default_tenor' => 12,
                'tenor_unit' => TenorUnit::Weeks,
                'interest_method' => InterestMethod::Flat,
                'interest_rate' => '40.0000',
                'repayment_frequency' => RepaymentFrequency::Weekly,
                'display_order' => 20,
            ]);

            $this->upsert([
                'code' => 'NPD-MONTHLY',
                'name' => 'Monthly Repayment',
                'description' => 'Repaid once a month.',
                'minimum_amount' => '50000.00',
                'maximum_amount' => '20000000.00',
                'minimum_tenor' => 1,
                'maximum_tenor' => 24,
                'default_tenor' => 6,
                'tenor_unit' => TenorUnit::Months,
                'interest_method' => InterestMethod::Flat,
                'interest_rate' => '40.0000',
                'repayment_frequency' => RepaymentFrequency::Monthly,
                'display_order' => 30,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $attributes): void
    {
        $existing = LoanProduct::query()->where('code', $attributes['code'])->first();

        // Pricing an administrator has changed is theirs. Only the description
        // is refreshed on an existing product; the numbers are left alone.
        if ($existing !== null) {
            $existing->description = $attributes['description'];
            $existing->save();

            return;
        }

        $product = new LoanProduct($attributes);

        $product->status = 'active';
        $product->interest_period = 'per_loan';
        $product->processing_fee_type = FeeType::None;
        $product->insurance_fee_type = FeeType::None;
        $product->late_payment_penalty_type = FeeType::None;
        $product->grace_period_days = 0;
        $product->requires_guarantor = false;
        $product->minimum_guarantors = 0;
        $product->requires_collateral = false;

        $product->save();
    }
}
