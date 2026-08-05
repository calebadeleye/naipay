<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLoanApplicationRequest extends FormRequest
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
            // The merchant and business an application is against are fixed
            // at creation; changing either is a new application, not an edit.
            'loan_product_id' => ['sometimes', 'integer', Rule::exists('loan_products', 'id')->whereNull('deleted_at')],
            'requested_amount' => ['sometimes', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'requested_tenor' => ['sometimes', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
