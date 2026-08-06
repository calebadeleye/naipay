<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Requests;

use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::StaffUpdate->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $staffId = $this->route('staff')?->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],

            'email' => ['sometimes', 'email', 'max:190', Rule::unique('staff', 'email')->ignore($staffId)],
            'username' => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('staff', 'username')->ignore($staffId)],
            'phone' => ['nullable', 'string', 'max:20'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:60'],

            // branch_id isn't accepted here — it has its own transfer
            // endpoint — so the only thing to guard is switching an existing
            // branch-less account TO Branch scope, which would leave it in
            // the same unable-to-see-its-own-records state StoreStaffRequest
            // guards against at creation.
            'access_scope' => [
                'sometimes',
                Rule::enum(AccessScope::class),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== AccessScope::Branch->value) {
                        return;
                    }

                    $staff = $this->route('staff');

                    if ($staff instanceof Staff && $staff->branch_id === null) {
                        $fail('This staff member has no branch. Transfer them to a branch first.');
                    }
                },
            ],

            // Roles, approval limits, branch transfers and status changes each
            // have their own endpoint: they are separately permissioned,
            // separately audited, and most require a reason.
        ];
    }
}
