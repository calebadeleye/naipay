<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Http\Requests;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::BankAccountsManage->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:150'],
            'bank_code' => ['nullable', 'string', 'max:10'],
            'account_name' => ['required', 'string', 'max:200'],
            // A Nigerian NUBAN: exactly ten digits.
            'account_number' => [
                'required', 'string', 'regex:/^\d{10}$/',
                Rule::unique('bank_accounts', 'account_number')->where('bank_name', $this->input('bank_name')),
            ],
            'branch_name' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'size:3'],
            'account_purpose' => ['required', Rule::enum(BankAccountPurpose::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_number.regex' => 'A Nigerian account number is exactly 10 digits.',
        ];
    }
}
