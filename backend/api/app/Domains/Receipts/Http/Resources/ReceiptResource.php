<?php

declare(strict_types=1);

namespace App\Domains\Receipts\Http\Resources;

use App\Domains\Receipts\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Receipt
 */
final class ReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Receipt $receipt */
        $receipt = $this->resource;

        return [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'amount' => $receipt->amount->jsonSerialize(),
            'issued_at' => $receipt->issued_at->toIso8601String(),

            'repayment' => $receipt->relationLoaded('repayment') && $receipt->repayment !== null ? [
                'id' => $receipt->repayment->id,
                'repayment_reference' => $receipt->repayment->repayment_reference,
                'payment_date' => $receipt->repayment->payment_date->toDateString(),
                'payment_method' => $receipt->repayment->payment_method->value,
            ] : null,

            'loan' => $receipt->relationLoaded('loan') && $receipt->loan !== null ? [
                'id' => $receipt->loan->id,
                'loan_reference' => $receipt->loan->loan_reference,
            ] : null,

            'merchant' => $receipt->relationLoaded('merchant') && $receipt->merchant !== null ? [
                'id' => $receipt->merchant->id,
                'merchant_number' => $receipt->merchant->merchant_number,
                'full_name' => $receipt->merchant->fullName(),
            ] : null,

            'business' => $receipt->relationLoaded('business') && $receipt->business !== null ? [
                'id' => $receipt->business->id,
                'business_name' => $receipt->business->business_name,
            ] : null,
        ];
    }
}
