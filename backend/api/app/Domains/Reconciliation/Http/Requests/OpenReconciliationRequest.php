<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OpenReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ReconciliationMatch->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->whereNull('deleted_at')],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'statement_opening_balance' => ['required', 'string', 'regex:/^-?\d{1,15}(\.\d{1,2})?$/'],
            'statement_closing_balance' => ['required', 'string', 'regex:/^-?\d{1,15}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
