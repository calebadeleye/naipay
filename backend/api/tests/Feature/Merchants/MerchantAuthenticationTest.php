<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Merchants\Notifications\MerchantActivationNotification;
use Database\Factories\MerchantFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('authentication');
        Notification::fake();
    }

    #[Test]
    public function an_activated_merchant_can_sign_in(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();

        $response = $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => MerchantFactory::PASSWORD,
        ])->assertOk();

        $this->assertIsString($response->json('data.token'));
        $this->assertSame($merchant->id, $response->json('data.merchant.id'));
    }

    #[Test]
    public function a_merchant_with_no_password_cannot_sign_in(): void
    {
        $merchant = Merchant::factory()->approved()->create();

        $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => 'whatever-they-guess',
        ])->assertStatus(403);
    }

    #[Test]
    public function an_unknown_email_and_a_wrong_password_report_the_same_error(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();

        $unknown = $this->postJson('/api/v1/merchant/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'irrelevant',
        ]);

        $wrong = $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => 'not-the-real-password',
        ]);

        $this->assertSame($unknown->json('message'), $wrong->json('message'));
        $this->assertSame($unknown->status(), $wrong->status());
    }

    #[Test]
    public function repeated_failures_lock_the_account(): void
    {
        // Lower than the throttle:authentication rate limit's own threshold,
        // so the lockout trips first and this asserts what it's meant to —
        // mirrors AuthenticationTest's equivalent staff test.
        config(['naipay.security.max_login_attempts' => 3]);

        $merchant = Merchant::factory()->approved()->withPassword()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/merchant/auth/login', [
                'email' => $merchant->email,
                'password' => 'wrong-password',
            ]);
        }

        $this->assertTrue($merchant->fresh()->isLocked());

        $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => MerchantFactory::PASSWORD,
        ])->assertStatus(423);
    }

    #[Test]
    public function a_suspended_merchant_cannot_sign_in(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();
        $merchant->forceFill(['merchant_status' => MerchantStatus::Suspended])->save();

        $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => MerchantFactory::PASSWORD,
        ])->assertStatus(403);
    }

    #[Test]
    public function me_returns_the_signed_in_merchant(): void
    {
        $merchant = $this->actingAsMerchant();

        $this->getJson('/api/v1/merchant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $merchant->id);
    }

    #[Test]
    public function logout_revokes_the_current_token(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();

        $token = $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => MerchantFactory::PASSWORD,
        ])->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/merchant/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/merchant/auth/me')->assertStatus(401);
    }

    #[Test]
    public function a_merchant_token_cannot_authenticate_an_admin_or_investor_route(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();

        $token = $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => MerchantFactory::PASSWORD,
        ])->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/staff')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/investor/dashboard')->assertStatus(401);
    }

    #[Test]
    public function an_activation_link_is_emailed_for_an_approved_merchant_with_no_password(): void
    {
        $merchant = Merchant::factory()->approved()->create();

        $this->postJson('/api/v1/merchant/auth/activate/request', ['email' => $merchant->email])
            ->assertOk();

        Notification::assertSentTo($merchant, MerchantActivationNotification::class);
    }

    #[Test]
    public function no_activation_link_is_sent_to_a_merchant_not_yet_approved(): void
    {
        $merchant = Merchant::factory()->create();

        $this->postJson('/api/v1/merchant/auth/activate/request', ['email' => $merchant->email])
            ->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    public function no_activation_link_is_sent_to_an_already_activated_merchant(): void
    {
        $merchant = Merchant::factory()->approved()->withPassword()->create();

        $this->postJson('/api/v1/merchant/auth/activate/request', ['email' => $merchant->email])
            ->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    public function activating_with_a_valid_token_sets_a_password_and_allows_sign_in(): void
    {
        $merchant = Merchant::factory()->approved()->create();
        $token = $this->requestActivationToken($merchant);

        $this->postJson('/api/v1/merchant/auth/activate', [
            'email' => $merchant->email,
            'token' => $token,
            'password' => 'Fresh-Portal-Pass1!',
            'password_confirmation' => 'Fresh-Portal-Pass1!',
        ])->assertOk();

        $refreshed = $merchant->fresh();
        $this->assertNotNull($refreshed->password);
        $this->assertNotNull($refreshed->activated_at);

        $this->postJson('/api/v1/merchant/auth/login', [
            'email' => $merchant->email,
            'password' => 'Fresh-Portal-Pass1!',
        ])->assertOk();
    }

    /**
     * Captures the plain-text activation token from the queued notification.
     */
    private function requestActivationToken(Merchant $merchant): string
    {
        $this->postJson('/api/v1/merchant/auth/activate/request', ['email' => $merchant->email])->assertOk();

        $captured = null;

        Notification::assertSentTo(
            $merchant,
            MerchantActivationNotification::class,
            function (MerchantActivationNotification $notification) use (&$captured, $merchant): bool {
                $mail = $notification->toMail($merchant);

                parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);

                $captured = $query['token'] ?? null;

                return true;
            },
        );

        $this->assertIsString($captured, 'No activation token was found in the notification.');

        return $captured;
    }
}
