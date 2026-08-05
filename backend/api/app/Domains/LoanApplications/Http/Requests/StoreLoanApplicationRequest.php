<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::LoanApplicationsCreate->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'merchant_id' => ['required', 'integer', Rule::exists('merchants', 'id')->whereNull('deleted_at')],

            // A business must belong to the merchant it is offered against —
            // it is what repayment capacity is actually assessed on.
            'business_id' => [
                'required',
                'integer',
                Rule::exists('businesses', 'id')
                    ->whereNull('deleted_at')
                    ->where('merchant_id', $this->input('merchant_id')),
            ],

            'loan_product_id' => ['required', 'integer', Rule::exists('loan_products', 'id')->whereNull('deleted_at')],

            'requested_amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'requested_tenor' => ['required', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:1000'],

            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_id.exists' => 'This business does not belong to the selected merchant.',
        ];
    }
}
