<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An active session, as shown on the operator's own security screen.
 *
 * The token itself is never exposed — only enough provenance for someone to
 * recognise their own devices and spot one they do not.
 *
 * @mixin PersonalAccessToken
 */
final class SessionResource extends JsonResource
{
    public function __construct(
        PersonalAccessToken $resource,
        private readonly bool $isCurrent = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource;

        return [
            'id' => $token->id,
            'device_name' => $token->device_name ?? 'Unknown device',
            'ip_address' => $token->ip_address,

            // The session making the request, which the console marks so an
            // operator does not revoke the device in front of them by mistake.
            'is_current' => $this->isCurrent,

            'created_at' => $token->created_at?->toIso8601String(),
            'last_activity_at' => $token->last_activity_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }
}
