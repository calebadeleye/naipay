<?php

declare(strict_types=1);

namespace App\Domains\Receipts\Services;

use App\Domains\Receipts\Models\Receipt;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Sequences\ReferenceGenerator;

/**
 * Issues the receipt for an approved repayment.
 *
 * Idempotent, the same reasoning as MerchantAccountService::openFor(): called
 * from inside RepaymentService::approve(), so a retried or replayed approval
 * must never leave a repayment holding two receipts.
 */
final class ReceiptService
{
    public function __construct(
        private readonly ReferenceGenerator $references,
    ) {}

    public function generateFor(Repayment $repayment): Receipt
    {
        $existing = Receipt::query()->where('repayment_id', $repayment->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        $receipt = new Receipt([
            'repayment_id' => $repayment->getKey(),
            'merchant_id' => $repayment->merchant_id,
            'business_id' => $repayment->business_id,
            'loan_id' => $repayment->loan_id,
            'amount' => $repayment->amount,
            'issued_at' => now(),
        ]);

        $receipt->receipt_number = $this->references->next('receipt');
        $receipt->save();

        return $receipt->fresh();
    }
}
