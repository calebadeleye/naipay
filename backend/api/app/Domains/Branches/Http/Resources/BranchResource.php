<?php

declare(strict_types=1);

namespace App\Domains\Branches\Http\Resources;

use App\Domains\Branches\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Branch
 */
final class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Branch $branch */
        $branch = $this->resource;

        return [
            'id' => $branch->id,
            'branch_code' => $branch->branch_code,
            'name' => $branch->name,
            'label' => $branch->label(),

            'address' => $branch->address,
            'city' => $branch->city,
            'state' => $branch->state,
            'country' => $branch->country,

            'phone' => $branch->phone,
            'email' => $branch->email,

            'status' => $branch->status->value,
            'status_label' => $branch->status->label(),
            'accepts_new_business' => $branch->status->acceptsNewBusiness(),

            'manager' => $this->whenLoaded('manager', fn (): ?array => $branch->manager === null ? null : [
                'id' => $branch->manager->id,
                'staff_number' => $branch->manager->staff_number,
                'full_name' => $branch->manager->fullName(),
            ]),

            // Only present where the caller asked for it, so a branch list does
            // not run a count per row.
            'staff_count' => $this->whenCounted('staff'),

            'opened_at' => $branch->opened_at?->toDateString(),
            'closed_at' => $branch->closed_at?->toDateString(),

            'created_at' => $branch->created_at?->toIso8601String(),
            'updated_at' => $branch->updated_at?->toIso8601String(),
        ];
    }
}
