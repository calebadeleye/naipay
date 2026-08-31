<?php

declare(strict_types=1);

namespace App\Domains\Loans\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class RescheduleLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::LoansRestructure->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'next_due_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
