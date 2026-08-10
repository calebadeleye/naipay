<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A merchant applying for their own loan through the self-service portal.
 *
 * Deliberately narrower than the staff-facing StoreLoanApplicationRequest:
 * there is no `merchant_id` field at all — the controller always forces it
 * from the authenticated merchant, never from the request body — and
 * `business_id` is validated against that same forced merchant, so a
 * merchant cannot apply for a loan against a business they don't own. No
 * `branch_id` either; that stays a staff/organisational concern.
 */
final class StoreMerchantLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->user();

        return [
            'business_id' => [
                'required',
                'integer',
                Rule::exists('businesses', 'id')
                    ->whereNull('deleted_at')
                    ->where('merchant_id', $merchant->id),
            ],

            'loan_product_id' => ['required', 'integer', Rule::exists('loan_products', 'id')->whereNull('deleted_at')],

            'requested_amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'requested_tenor' => ['required', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_id.exists' => 'This business does not belong to your account.',
        ];
    }
}
