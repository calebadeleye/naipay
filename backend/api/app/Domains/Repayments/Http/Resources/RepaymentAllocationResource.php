<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Http\Resources;

use App\Domains\Repayments\Models\RepaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RepaymentAllocation
 */
final class RepaymentAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RepaymentAllocation $allocation */
        $allocation = $this->resource;

        return [
            'loan_schedule_entry_id' => $allocation->loan_schedule_entry_id,
            'installment_number' => $allocation->relationLoaded('scheduleEntry') && $allocation->scheduleEntry !== null
                ? $allocation->scheduleEntry->installment_number
                : null,
            'principal_amount' => $allocation->principal_amount->toDecimalString(),
            'interest_amount' => $allocation->interest_amount->toDecimalString(),
            'fee_amount' => $allocation->fee_amount->toDecimalString(),
        ];
    }
}
