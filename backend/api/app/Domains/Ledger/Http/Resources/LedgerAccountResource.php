<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Http\Resources;

use App\Domains\Ledger\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerAccount
 */
final class LedgerAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LedgerAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'description' => $account->description,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'is_system' => $account->is_system,
            'status' => $account->status,
            'is_active' => $account->isActive(),
            'balance' => $account->currentBalance()->jsonSerialize(),
        ];
    }
}
