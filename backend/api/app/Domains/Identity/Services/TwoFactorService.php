<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Models\Staff;
use App\Support\Exceptions\DomainException;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * Time-based one-time password (TOTP) enrolment and verification.
 *
 * Works with any authenticator app. Naipay stores only the shared secret,
 * encrypted at rest, and never transmits or logs a code.
 */
final class TwoFactorService
{
    /**
     * How many 30-second steps either side of now are accepted.
     *
     * One step tolerates ordinary clock drift between a phone and the server.
     * Widening it would materially enlarge the window in which an intercepted
     * code stays usable.
     */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        private readonly Google2FA $google2fa,
    ) {}

    /**
     * Begins enrolment: generates a secret and returns the provisioning
     * details for the operator's authenticator app.
     *
     * The secret is stored but two-factor is NOT yet active — that requires
     * confirm(). Issuing a secret without proving the operator can generate a
     * code from it would lock them out of their own account.
     *
     * @return array{secret: string, otpauth_url: string, qr_code_svg: string}
     */
    public function beginEnrolment(Staff $staff): array
    {
        if ($staff->hasTwoFactorEnabled()) {
            throw new DomainException(
                'Two-factor authentication is already enabled. Disable it first to enrol a new device.'
            );
        }

        $secret = $this->google2fa->generateSecretKey(32);

        $staff->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $otpauthUrl = $this->google2fa->getQRCodeUrl(
            (string) config('naipay.brand.name'),
            $staff->email,
            $secret,
        );

        return [
            'secret' => $secret,
            'otpauth_url' => $otpauthUrl,
            'qr_code_svg' => $this->renderQrCode($otpauthUrl),
        ];
    }

    /**
     * Completes enrolment by verifying a code produced from the new secret.
     *
     * @return array<int, string> Recovery codes, shown exactly once.
     */
    public function confirm(Staff $staff, string $code): array
    {
        if ($staff->two_factor_secret === null) {
            throw new DomainException('Start two-factor enrolment before confirming it.');
        }

        if ($staff->two_factor_confirmed_at !== null) {
            throw new DomainException('Two-factor authentication is already enabled.');
        }

        if (! $this->verifyCode($staff->two_factor_secret, $code)) {
            throw new DomainException(
                'That code is not valid. Check your authenticator app and try again.',
                ['code' => ['The verification code is incorrect.']],
            );
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $staff->forceFill([
            // Hashed, like passwords: a recovery code is a credential, and a
            // database read must not yield a usable one.
            'two_factor_recovery_codes' => array_map(
                static fn (string $plain): string => Hash::make($plain),
                $recoveryCodes,
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Verifies a challenge during sign-in, accepting either a TOTP code or an
     * unused recovery code.
     *
     * A recovery code is consumed on use.
     */
    public function verifyChallenge(Staff $staff, string $code): bool
    {
        if (! $staff->hasTwoFactorEnabled()) {
            return false;
        }

        $code = trim($code);

        // A six-digit value is a TOTP code; anything else is treated as a
        // recovery code, which avoids burning one on a mistyped TOTP.
        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $this->verifyCode((string) $staff->two_factor_secret, $code);
        }

        return $this->consumeRecoveryCode($staff, $code);
    }

    /**
     * Turns two-factor off.
     *
     * Refused for accounts whose role or permissions make it mandatory —
     * otherwise an operator holding ledger or access-control rights could
     * reduce themselves to a single factor.
     */
    public function disable(Staff $staff): void
    {
        if ($staff->requiresTwoFactor()) {
            throw new DomainException(
                'Two-factor authentication is mandatory for your role and cannot be disabled.'
            );
        }

        $staff->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Issues a fresh set of recovery codes, invalidating the previous set.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(Staff $staff): array
    {
        if (! $staff->hasTwoFactorEnabled()) {
            throw new DomainException('Enable two-factor authentication before generating recovery codes.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $staff->forceFill([
            'two_factor_recovery_codes' => array_map(
                static fn (string $plain): string => Hash::make($plain),
                $recoveryCodes,
            ),
        ])->save();

        return $recoveryCodes;
    }

    public function remainingRecoveryCodes(Staff $staff): int
    {
        return count($staff->two_factor_recovery_codes ?? []);
    }

    private function verifyCode(string $secret, string $code): bool
    {
        // The library raises on a malformed secret or code rather than
        // returning false; a bad input is simply a failed verification.
        try {
            return $this->google2fa->verifyKey($secret, $code, self::WINDOW);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Matches and removes a recovery code.
     *
     * Every stored hash is checked rather than stopping at the first match, so
     * the time taken does not reveal the position of a valid code.
     */
    private function consumeRecoveryCode(Staff $staff, string $code): bool
    {
        $stored = $staff->two_factor_recovery_codes ?? [];

        if ($stored === []) {
            return false;
        }

        $matchedIndex = null;

        foreach ($stored as $index => $hash) {
            if (Hash::check($code, $hash)) {
                $matchedIndex = $index;
            }
        }

        if ($matchedIndex === null) {
            return false;
        }

        unset($stored[$matchedIndex]);

        $staff->forceFill([
            'two_factor_recovery_codes' => array_values($stored),
        ])->save();

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // Grouped as XXXXX-XXXXX so it can be read aloud and typed
            // accurately under pressure.
            $codes[] = mb_strtoupper(Str::random(5).'-'.Str::random(5));
        }

        return $codes;
    }

    /**
     * SVG rather than a raster image: it needs no imaging extension, scales
     * cleanly, and embeds directly in the enrolment screen.
     */
    private function renderQrCode(string $otpauthUrl): string
    {
        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(220, 1),
                new SvgImageBackEnd,
            )
        );

        return $writer->writeString($otpauthUrl);
    }
}
