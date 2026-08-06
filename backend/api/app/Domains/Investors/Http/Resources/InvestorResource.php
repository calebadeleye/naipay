<?php

declare(strict_types=1);

namespace App\Domains\Investors\Http\Resources;

use App\Domains\Investors\Models\Investor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An investor account as returned to the investor portal.
 *
 * @mixin Investor
 */
final class InvestorResource extends JsonResource
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
            'last_login_at' => $investor->last_login_at?->toIso8601String(),
            'created_at' => $investor->created_at?->toIso8601String(),
        ];
    }
}
