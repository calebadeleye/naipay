<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Naipay's personal access token.
 *
 * Extends Sanctum's model to carry the device and network provenance added by
 * the identity migration, and — importantly — to cast `last_activity_at`.
 * Without the cast it comes back as a raw string and the idle-timeout check
 * cannot compare it.
 *
 * @property string|null $device_name
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $reauthenticated_at
 */
// Deliberately not final: Sanctum::actingAs() substitutes a mock of the
// configured token model, which a final class cannot provide.
class AccessToken extends PersonalAccessToken
{
    /**
     * Stated explicitly: Eloquent would otherwise infer `access_tokens` from
     * this class name, whereas Sanctum's schema is `personal_access_tokens`.
     */
    protected $table = 'personal_access_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'device_name',
        'ip_address',
        'user_agent',
        'last_activity_at',
        'reauthenticated_at',
    ];

    /**
     * When this session was last used.
     *
     * Falls back to issue time for a token created before activity tracking
     * existed. Returns null when neither is a real timestamp — which is the
     * case for the transient token Sanctum::actingAs() substitutes in tests,
     * where there is no session to age.
     */
    public function lastActivity(): ?CarbonInterface
    {
        foreach ([$this->last_activity_at, $this->created_at] as $candidate) {
            if ($candidate instanceof CarbonInterface) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Minutes since this session was last used, or zero when unknown.
     */
    public function idleMinutes(): float
    {
        return $this->lastActivity()?->diffInMinutes(now(), absolute: true) ?? 0.0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'last_activity_at' => 'datetime',
            'reauthenticated_at' => 'datetime',
        ]);
    }
}
