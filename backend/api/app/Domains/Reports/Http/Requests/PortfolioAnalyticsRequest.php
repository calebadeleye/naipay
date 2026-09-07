<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Reports\Support\PortfolioPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the portfolio dashboard's filter query string. Everything is a
 * GET parameter so a filtered dashboard is a shareable URL.
 */
final class PortfolioAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ReportsView->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'string', Rule::in(PortfolioPeriod::PRESETS)],
            'date_from' => ['required_if:range,custom', 'nullable', 'date'],
            'date_to' => ['required_if:range,custom', 'nullable', 'date', 'after_or_equal:date_from'],

            'loan_product_id' => ['sometimes', 'nullable', 'integer', Rule::exists('loan_products', 'id')],
            'status' => ['sometimes', 'nullable', Rule::enum(LoanStatus::class)],
            'loan_officer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('staff', 'id')],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')],
            'borrower_id' => ['sometimes', 'nullable', 'integer', Rule::exists('merchants', 'id')],
            'loan_id' => ['sometimes', 'nullable', 'integer', Rule::exists('loans', 'id')],
            'disbursement_channel_id' => ['sometimes', 'nullable', 'integer', Rule::exists('bank_accounts', 'id')],

            'repayment_status' => ['sometimes', 'nullable', Rule::in(['current', 'overdue'])],
            'min_days_past_due' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ];
    }
}
