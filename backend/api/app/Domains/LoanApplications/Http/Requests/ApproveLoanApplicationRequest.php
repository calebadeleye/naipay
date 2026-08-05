<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Final approval. Both fields are optional overrides of what was requested —
 * a credit manager may approve less than was asked for, but never needs to
 * restate the figures to approve as-is.
 */
final class ApproveLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::LoanApplicationsApprove->value) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'approved_amount' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'approved_tenor' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
