<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Domains\Identity\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff account as returned to the administrative console.
 *
 * @mixin Staff
 */
final class StaffResource extends JsonResource
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

            'status' => $staff->status->value,
            'status_label' => $staff->status->label(),

            'roles' => $staff->roleNames(),
            // Flattened across roles so the console can drive its navigation
            // and action visibility from one list. The API re-checks every
            // permission server-side regardless of what the client rendered.
            'permissions' => $staff->permissionNames(),

            'two_factor' => [
                'enabled' => $staff->hasTwoFactorEnabled(),
                'required' => $staff->requiresTwoFactor(),
                'confirmed_at' => $staff->two_factor_confirmed_at?->toIso8601String(),
            ],

            'must_change_password' => $staff->must_change_password,
            'password_changed_at' => $staff->password_changed_at?->toIso8601String(),

            'last_login_at' => $staff->last_login_at?->toIso8601String(),
            'last_login_ip' => $staff->last_login_ip,

            'created_at' => $staff->created_at?->toIso8601String(),
            'updated_at' => $staff->updated_at?->toIso8601String(),
        ];
    }
}
