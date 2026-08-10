<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A merchant editing their own business through the self-service portal.
 *
 * Deliberately narrower than the staff-facing UpdateBusinessRequest: no
 * `business_type` (changing it plausibly invalidates verification already
 * done against the current type, so it stays a staff-reviewed change), no
 * `status`/`verification_status` (service-set only, same as for staff).
 * Ownership is implicit — the controller confirms the business belongs to
 * the authenticated merchant before this request is even validated — so
 * authorize() needs no permission check.
 */
final class UpdateBusinessSelfRequest extends FormRequest
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
            'business_name' => ['sometimes', 'string', 'max:200'],
            'registered_business_name' => ['nullable', 'string', 'max:200'],
            'cac_registration_number' => ['nullable', 'string', 'max:40'],

            'business_description' => ['nullable', 'string', 'max:2000'],

            'business_category_id' => ['sometimes', 'integer', Rule::exists('business_categories', 'id')],
            'business_subcategory_id' => ['nullable', 'integer', Rule::exists('business_categories', 'id')],

            'business_phone' => ['nullable', 'string', 'max:20'],
            'business_email' => ['nullable', 'email', 'max:190'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'business_website' => ['nullable', 'url', 'max:255'],
            'social_media_links' => ['nullable', 'array'],

            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'year_established' => ['nullable', 'integer', 'min:1900', 'max:'.date('Y')],
            'number_of_employees' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'estimated_monthly_revenue' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'estimated_monthly_expenses' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'average_monthly_sales' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estimated_monthly_revenue.regex' => 'Enter a valid amount, using at most two decimal places.',
            'estimated_monthly_expenses.regex' => 'Enter a valid amount, using at most two decimal places.',
            'average_monthly_sales.regex' => 'Enter a valid amount, using at most two decimal places.',
        ];
    }
}
