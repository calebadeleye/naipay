<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A merchant editing their own profile through the self-service portal.
 *
 * Deliberately narrower than the staff-facing UpdateMerchantRequest: no
 * `bvn`/`nin` (identity numbers stay a staff/KYC-verification concern) and no
 * `branch_id`/`assigned_officer_id` (internal organisational assignment).
 * Ownership is implicit — the merchant can only ever be the authenticated
 * user — so authorize() needs no permission check.
 */
final class UpdateMerchantSelfRequest extends FormRequest
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
        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],

            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'in:male,female,other,prefer_not_to_say'],
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
