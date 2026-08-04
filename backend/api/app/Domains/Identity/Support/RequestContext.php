<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use Illuminate\Http\Request;

/**
 * Network and device provenance for an authentication event.
 *
 * Captured on sign-in and stored against the issued token so an operator
 * reviewing their active sessions sees something they can recognise, rather
 * than an opaque row.
 */
final class RequestContext
{
    public function __construct(
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly string $deviceName,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $userAgent = $request->userAgent();

        return new self(
            ipAddress: $request->ip(),
            // Bounded before it reaches the database: the header is
            // attacker-controlled and otherwise unlimited in length.
            userAgent: $userAgent !== null ? mb_substr($userAgent, 0, 1000) : null,
            deviceName: self::describeDevice($userAgent),
        );
    }

    /**
     * Reduces a user agent to something an operator recognises, such as
     * "Chrome on Windows".
     *
     * Deliberately coarse. This is for human recognition on a session list,
     * not fingerprinting, and a precise parse would be a dependency and a
     * maintenance burden for no operational gain.
     */
    public static function describeDevice(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            // Chrome must be tested before Safari: Chrome's UA also claims Safari.
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            str_contains($userAgent, 'PostmanRuntime') => 'Postman',
            str_contains($userAgent, 'curl/') => 'curl',
            default => null,
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac OS X'), str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }
}
