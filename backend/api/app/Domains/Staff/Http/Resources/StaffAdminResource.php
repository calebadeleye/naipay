<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Resources;

use App\Domains\Identity\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff record as seen by an administrator managing other staff.
 *
 * Distinct from StaffResource, which is what a staff member sees of
 * themselves. This one carries organisational detail — approval limit, access
 * scope, suspension reason — and never carries the permission list, which is
 * only meaningful for the signed-in operator's own client.
 *
 * @mixin Staff
 */
final class StaffAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Staff $staff */
        $staff = $this->resource;

        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,

            'first_name' => $staff->first_name,
            'middle_name' => $staff->middle_name,
            'last_name' => $staff->last_name,
            'full_name' => $staff->fullName(),
            'initials' => $staff->initials(),

            'email' => $staff->email,
            'username' => $staff->username,
            'phone' => $staff->phone,
            'job_title' => $staff->job_title,
            'department' => $staff->department,

            'status' => $staff->status->value,
            'status_label' => $staff->status->label(),
            'is_locked' => $staff->isLocked(),
            'locked_until' => $staff->locked_until?->toIso8601String(),

            'suspension_reason' => $staff->suspension_reason,
            'suspended_at' => $staff->suspended_at?->toIso8601String(),

            'access_scope' => $staff->accessScope()->value,
            'access_scope_label' => $staff->accessScope()->label(),

            'branch' => $staff->branch === null ? null : [
                'id' => $staff->branch->id,
                'branch_code' => $staff->branch->branch_code,
                'name' => $staff->branch->name,
            ],

            // Serialised through Money so the client receives the exact decimal
            // string alongside a formatted display value, never a float.
            'approval_limit' => $staff->approval_limit?->jsonSerialize(),
            'has_approval_authority' => $staff->hasApprovalAuthority(),

            'roles' => $staff->roleNames(),

            'two_factor' => [
                'enabled' => $staff->hasTwoFactorEnabled(),
                'required' => $staff->requiresTwoFactor(),
            ],

            'must_change_password' => $staff->must_change_password,
            'last_login_at' => $staff->last_login_at?->toIso8601String(),
            'last_login_ip' => $staff->last_login_ip,

            'created_at' => $staff->created_at?->toIso8601String(),
            'updated_at' => $staff->updated_at?->toIso8601String(),
        ];
    }
}
