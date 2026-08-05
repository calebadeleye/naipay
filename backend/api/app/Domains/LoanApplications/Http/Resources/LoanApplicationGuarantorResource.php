<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Resources;

use App\Domains\LoanApplications\Models\Guarantor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guarantor
 */
final class LoanApplicationGuarantorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Guarantor $guarantor */
        $guarantor = $this->resource;

        return [
            'id' => $guarantor->id,
            'loan_application_id' => $guarantor->loan_application_id,

            'full_name' => $guarantor->full_name,
            'phone' => $guarantor->phone,
            'email' => $guarantor->email,
            'relationship' => $guarantor->relationship,
            'address' => $guarantor->address,

            'id_type' => $guarantor->id_type,
            'id_number' => $guarantor->id_number,

            'employer' => $guarantor->employer,
            'occupation' => $guarantor->occupation,
            'monthly_income' => $guarantor->monthly_income?->jsonSerialize(),

            'created_at' => $guarantor->created_at?->toIso8601String(),
        ];
    }
}
