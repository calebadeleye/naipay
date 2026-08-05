<?php

declare(strict_types=1);

namespace App\Domains\Loans\Http\Resources;

use App\Domains\Loans\Models\LoanScheduleEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanScheduleEntry
 */
final class LoanScheduleEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoanScheduleEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'installment_number' => $entry->installment_number,
            'due_date' => $entry->due_date->toDateString(),
            'opening_principal' => $entry->opening_principal->toDecimalString(),
            'principal_due' => $entry->principal_due->toDecimalString(),
            'interest_due' => $entry->interest_due->toDecimalString(),
            'fee_due' => $entry->fee_due->toDecimalString(),
            'total_due' => $entry->totalDue()->toDecimalString(),
            'principal_paid' => $entry->principal_paid->toDecimalString(),
            'interest_paid' => $entry->interest_paid->toDecimalString(),
            'fee_paid' => $entry->fee_paid->toDecimalString(),
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
        ];
    }
}
