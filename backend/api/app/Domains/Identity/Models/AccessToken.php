<?php

declare(strict_types=1);

namespace App\Domains\Identity\Models;

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
 */
final class AccessToken extends PersonalAccessToken
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
    ];

    /**
     * Minutes since this session was last used.
     */
    public function idleMinutes(): float
    {
        $reference = $this->last_activity_at ?? $this->created_at;

        return $reference === null ? 0.0 : $reference->diffInMinutes(now(), absolute: true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'last_activity_at' => 'datetime',
        ]);
    }
}
