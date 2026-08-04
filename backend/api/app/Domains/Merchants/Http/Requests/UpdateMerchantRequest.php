<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::MerchantsUpdate->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],

            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'marital_status' => ['nullable', 'string', 'max:30'],
            'employment_status' => ['nullable', 'string', 'max:40'],
            'preferred_language' => ['nullable', 'string', 'max:40'],

            // Nigerian mobile numbers, local or international form.
            'phone' => ['sometimes', 'string', 'regex:/^(\+?234|0)[7-9][01]\d{8}$/'],
            'alternative_phone' => ['nullable', 'string', 'regex:/^(\+?234|0)[7-9][01]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],

            'residential_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],

            // Uniqueness is checked against the blind index in the service, not
            // by a `unique` rule: the stored column is ciphertext and differs
            // on every write.
            'bvn' => ['nullable', 'string', 'regex:/^\d{11}$/'],
            'nin' => ['nullable', 'string', 'regex:/^\d{11}$/'],

            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'assigned_officer_id' => ['nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Nigerian phone number, for example 08031234567.',
            'bvn.regex' => 'A BVN is exactly 11 digits.',
            'nin.regex' => 'A NIN is exactly 11 digits.',
        ];
    }
}
