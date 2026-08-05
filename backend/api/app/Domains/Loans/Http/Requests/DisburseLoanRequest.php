<?php

declare(strict_types=1);

namespace App\Domains\Loans\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class DisburseLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::LoansDisburse->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'disbursement_date' => ['nullable', 'date'],
        ];
    }
}
