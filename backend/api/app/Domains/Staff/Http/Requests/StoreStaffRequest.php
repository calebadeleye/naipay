<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Requests;

use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::StaffCreate->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],

            'email' => ['required', 'email', 'max:190', Rule::unique('staff', 'email')],
            'username' => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('staff', 'username')],
            'phone' => ['nullable', 'string', 'max:20'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:60'],

            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'access_scope' => ['sometimes', Rule::enum(AccessScope::class)],

            // Assigning a role at creation is convenient and safe: the creator
            // is not the subject, so no self-escalation is possible.
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', Rule::in(Role::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
