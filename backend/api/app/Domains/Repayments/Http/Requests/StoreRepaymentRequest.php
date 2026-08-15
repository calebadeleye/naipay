<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Repayments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::RepaymentsRecord->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'loan_id' => ['required', 'integer', Rule::exists('loans', 'id')->whereNull('deleted_at')],
            'receiving_bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->whereNull('deleted_at')],

            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],

            'sender_account_name' => ['nullable', 'string', 'max:200'],
            'sender_account_number' => ['nullable', 'string', 'max:34'],
            'sender_bank_name' => ['nullable', 'string', 'max:150'],
            'bank_reference' => ['nullable', 'string', 'max:100'],

            'notes' => ['nullable', 'string', 'max:1000'],

            // Set only on a resubmission, after an officer has seen and
            // dismissed a possible-duplicate warning.
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }
}
