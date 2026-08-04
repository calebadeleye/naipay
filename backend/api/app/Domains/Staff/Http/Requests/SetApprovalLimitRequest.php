<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class SetApprovalLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::StaffSetApprovalLimit->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // A decimal string, never a float: the limit is compared against
            // loan amounts and must be exact. Null removes approval authority,
            // which is distinct from a limit of zero.
            'approval_limit' => ['present', 'nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approval_limit.regex' => 'Enter a valid amount, using at most two decimal places.',
        ];
    }
}
