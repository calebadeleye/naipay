<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Reconciliation\Enums\BankStatementLineDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBankStatementLineRequest extends FormRequest
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
            'statement_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'direction' => ['required', Rule::enum(BankStatementLineDirection::class)],
        ];
    }
}
