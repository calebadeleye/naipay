<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Requests;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::StaffAssignRoles->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Roles are replaced wholesale rather than added to, so the payload
            // always states the complete intended set. An empty array revokes
            // every role, which is a legitimate thing to want.
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(Role::values())],
        ];
    }
}
