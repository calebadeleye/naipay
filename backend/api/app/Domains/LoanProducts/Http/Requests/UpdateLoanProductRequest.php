<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use App\Domains\LoanProducts\Enums\FeeType;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\LoanProducts\Enums\TenorUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::LoanProductsManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            // Decimal strings throughout. A float here would change what a
            // merchant owes.
            'minimum_amount' => ['sometimes', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'maximum_amount' => ['sometimes', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/', 'gte:minimum_amount'],

            'minimum_tenor' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'maximum_tenor' => ['sometimes', 'integer', 'min:1', 'max:3650', 'gte:minimum_tenor'],
            'default_tenor' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'tenor_unit' => ['sometimes', Rule::enum(TenorUnit::class)],

            'interest_method' => ['sometimes', Rule::enum(InterestMethod::class)],
            // Up to four decimal places, so a rate like 1.6667% survives.
            'interest_rate' => ['sometimes', 'string', 'regex:/^\d{1,4}(\.\d{1,4})?$/'],
            'interest_period' => ['nullable', 'string', 'max:20'],

            'repayment_frequency' => ['sometimes', Rule::enum(RepaymentFrequency::class)],

            'processing_fee_type' => ['sometimes', Rule::enum(FeeType::class)],
            'processing_fee_value' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'insurance_fee_type' => ['sometimes', Rule::enum(FeeType::class)],
            'insurance_fee_value' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'late_payment_penalty_type' => ['sometimes', Rule::enum(FeeType::class)],
            'late_payment_penalty_value' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],

            'grace_period_days' => ['sometimes', 'integer', 'min:0', 'max:365'],

            'requires_guarantor' => ['sometimes', 'boolean'],
            'minimum_guarantors' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'requires_collateral' => ['sometimes', 'boolean'],

            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'maximum_amount.gte' => 'The maximum amount must not be below the minimum.',
            'maximum_tenor.gte' => 'The maximum tenor must not be below the minimum.',
            'interest_rate.regex' => 'Enter the rate as a percentage, for example 20 or 20.5.',
        ];
    }
}
