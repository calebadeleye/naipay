<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MatchBankStatementLineRequest extends FormRequest
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
            'matched_to_type' => ['required', Rule::in(['repayment', 'loan'])],
            'matched_to_id' => ['required', 'integer'],
        ];
    }
}
