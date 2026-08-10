<?php

declare(strict_types=1);

namespace App\Domains\Investors\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class ChangeInvestorStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::InvestorsSuspend->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Suspension revokes an external stakeholder's access. The reason
            // is what makes the trail defensible.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Give a reason of at least 10 characters. This is recorded in the audit log.',
        ];
    }
}
