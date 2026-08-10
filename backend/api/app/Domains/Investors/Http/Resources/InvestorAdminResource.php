<?php

declare(strict_types=1);

namespace App\Domains\Investors\Http\Resources;

use App\Domains\Investors\Models\Investor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An investor record as seen by the staff managing it.
 *
 * Distinct from InvestorResource, which is what the investor sees of
 * themselves on their own dashboard. This one carries administrative detail —
 * who created the account, the suspension reason, sign-in security state —
 * that has no place on the investor-facing surface.
 *
 * @mixin Investor
 */
final class InvestorAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Investor $investor */
        $investor = $this->resource;

        return [
            'id' => $investor->id,
            'investor_number' => $investor->investor_number,

            'name' => $investor->name,
            'initials' => $investor->initials(),
            'email' => $investor->email,
            'phone' => $investor->phone,

            'status' => $investor->status->value,
            'status_label' => $investor->status->label(),
            'is_locked' => $investor->isLocked(),
            'locked_until' => $investor->locked_until?->toIso8601String(),
            'failed_login_attempts' => $investor->failed_login_attempts,

            'suspension_reason' => $investor->suspension_reason,
            'suspended_at' => $investor->suspended_at?->toIso8601String(),

            'created_by' => $investor->relationLoaded('createdBy') && $investor->createdBy !== null ? [
                'id' => $investor->createdBy->id,
                'name' => $investor->createdBy->fullName(),
            ] : null,

            'last_login_at' => $investor->last_login_at?->toIso8601String(),
            'last_login_ip' => $investor->last_login_ip,

            'created_at' => $investor->created_at?->toIso8601String(),
            'updated_at' => $investor->updated_at?->toIso8601String(),
        ];
    }
}
