<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class StoreLoanApplicationGuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::LoanApplicationsUpdate->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^(\+?234|0)[7-9][01]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'relationship' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],

            'id_type' => ['nullable', 'string', 'max:40'],
            'id_number' => ['nullable', 'string', 'max:40'],

            'employer' => ['nullable', 'string', 'max:150'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'monthly_income' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Nigerian phone number, for example 08031234567.',
        ];
    }
}
