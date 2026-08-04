<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

final class ChangeStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::StaffDisable->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Suspension and disabling revoke someone's access to a financial
            // system. The reason is what makes the trail defensible.
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
