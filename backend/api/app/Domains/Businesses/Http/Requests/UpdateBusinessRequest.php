<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Requests;

use App\Domains\Businesses\Enums\BusinessType;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::BusinessesUpdate->value) ?? false;
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

            'business_type' => ['sometimes', Rule::enum(BusinessType::class)],
            'business_description' => ['nullable', 'string', 'max:2000'],

            // Chosen from the controlled vocabulary. The service additionally
            // checks the pair is coherent — a subcategory must belong to the
            // category chosen, or a business ends up filed under
            // "Agriculture > Hairdressing".
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

            // Decimal strings, never floats: these feed repayment-capacity
            // assessment and must round-trip exactly.
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
