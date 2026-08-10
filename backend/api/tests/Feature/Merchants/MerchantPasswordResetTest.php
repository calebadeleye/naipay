<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Merchants\Models\Merchant;
use App\Domains\Merchants\Notifications\MerchantPasswordResetNotification;
use Database\Factories\MerchantFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Reset-Portal-Pass1!';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('authentication');
        Notification::fake();
    }

    #[Test]
    public function a_reset_link_is_emailed_for_an_activated_merchant(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();

        $this->postJson('/api/v1/merchant/auth/forgot-password', ['email' => $merchant->email])->assertOk();

        Notification::assertSentTo($merchant, MerchantPasswordResetNotification::class);
    }

    #[Test]
    public function no_reset_link_is_sent_to_a_merchant_who_has_not_yet_activated(): void
    {
        $merchant = Merchant::factory()->approved()->create();

        $this->postJson('/api/v1/merchant/auth/forgot-password', ['email' => $merchant->email])->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_password_can_be_reset_with_a_valid_token(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();
        $token = $this->requestResetToken($merchant);

        $this->postJson('/api/v1/merchant/auth/reset-password', [
            'email' => $merchant->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $merchant->fresh()->password));
    }

    #[Test]
    public function a_reset_token_is_single_use(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();
        $token = $this->requestResetToken($merchant);

        $this->postJson('/api/v1/merchant/auth/reset-password', [
            'email' => $merchant->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/v1/merchant/auth/reset-password', [
            'email' => $merchant->email,
            'token' => $token,
            'password' => 'Another-Portal-Pass1!',
            'password_confirmation' => 'Another-Portal-Pass1!',
        ])->assertStatus(422);
    }

    #[Test]
    public function an_expired_reset_token_is_refused(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();
        $token = $this->requestResetToken($merchant);

        $this->travel(61)->minutes();

        $this->postJson('/api/v1/merchant/auth/reset-password', [
            'email' => $merchant->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);
    }

    #[Test]
    public function resetting_a_password_revokes_every_session(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();

        $sessionOne = $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => MerchantFactory::PASSWORD,
        ])->assertOk()->json('data.token');

        $token = $this->requestResetToken($merchant);

        $this->postJson('/api/v1/merchant/auth/reset-password', [
            'email' => $merchant->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($sessionOne)->getJson('/api/v1/merchant/auth/me')->assertStatus(401);
    }

    private function requestResetToken(Merchant $merchant): string
    {
        $this->postJson('/api/v1/merchant/auth/forgot-password', ['email' => $merchant->email])->assertOk();

        $captured = null;

        Notification::assertSentTo(
            $merchant,
            MerchantPasswordResetNotification::class,
            function (MerchantPasswordResetNotification $notification) use (&$captured, $merchant): bool {
                $mail = $notification->toMail($merchant);

                parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);

                $captured = $query['token'] ?? null;

                return true;
            },
        );

        $this->assertIsString($captured, 'No reset token was found in the notification.');

        return $captured;
    }
}
