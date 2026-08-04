<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Resources;

use App\Domains\Identity\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a login history.
 *
 * @mixin LoginAttempt
 */
final class LoginAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoginAttempt $attempt */
        $attempt = $this->resource;

        return [
            'id' => $attempt->id,
            'successful' => $attempt->successful,
            'failure_reason' => $attempt->failure_reason,
            'failure_label' => $this->describeFailure($attempt->failure_reason),
            'ip_address' => $attempt->ip_address,
            'device_name' => $attempt->device_name,
            'attempted_at' => $attempt->attempted_at->toIso8601String(),
        ];
    }

    private function describeFailure(?string $reason): ?string
    {
        return match ($reason) {
            LoginAttempt::FAILURE_INVALID_CREDENTIALS => 'Incorrect password',
            LoginAttempt::FAILURE_ACCOUNT_LOCKED => 'Account locked',
            LoginAttempt::FAILURE_ACCOUNT_INACTIVE => 'Account not active',
            LoginAttempt::FAILURE_INVALID_TWO_FACTOR => 'Incorrect verification code',
            LoginAttempt::FAILURE_EXPIRED_CHALLENGE => 'Sign-in attempt expired',
            default => null,
        };
    }
}
