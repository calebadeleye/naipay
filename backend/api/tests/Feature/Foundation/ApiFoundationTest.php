<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\FinancialIntegrityException;
use App\Support\Http\ApiResponse;
use App\Support\Http\Middleware\AssignCorrelationId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the contract every other endpoint in Naipay inherits: the response
 * envelope, error shape, correlation IDs and security headers.
 */
final class ApiFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_api_is_versioned_under_v1(): void
    {
        $this->getJson('/api/v1/health')->assertOk();

        // An unversioned path must not resolve.
        $this->getJson('/api/health')->assertNotFound();
    }

    #[Test]
    public function the_health_endpoint_reports_dependency_status(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.checks.database.status', 'up')
            ->assertJsonPath('meta.application', 'Naipay')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['status', 'checks' => ['database', 'cache', 'redis']],
                'meta' => ['application', 'environment', 'version', 'time'],
            ]);
    }

    #[Test]
    public function successful_responses_use_the_standard_envelope(): void
    {
        Route::middleware('api')->get('/api/v1/_test/envelope', fn () => ApiResponse::success(
            data: ['merchant_number' => 'NPM-000001'],
            message: 'Merchant created successfully.',
            meta: ['branch' => 'Lagos Mainland'],
        ));

        $this->getJson('/api/v1/_test/envelope')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Merchant created successfully.',
                'data' => ['merchant_number' => 'NPM-000001'],
                'meta' => ['branch' => 'Lagos Mainland'],
            ]);
    }

    #[Test]
    public function an_empty_payload_is_an_object_rather_than_null(): void
    {
        Route::middleware('api')->get('/api/v1/_test/empty', fn () => ApiResponse::success());

        // Clients treat `data` as an object; returning null would force every
        // consumer to null-check before destructuring.
        $this->getJson('/api/v1/_test/empty')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Request completed successfully.',
                'data' => [],
                'meta' => [],
            ]);
    }

    #[Test]
    public function validation_failures_use_the_documented_error_shape(): void
    {
        Route::middleware('api')->post('/api/v1/_test/validation', function (): never {
            throw ValidationException::withMessages([
                'requested_amount' => ['The requested amount is required.'],
            ]);
        });

        $this->postJson('/api/v1/_test/validation')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.requested_amount.0', 'The requested amount is required.')
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    #[Test]
    public function a_domain_rule_violation_returns_its_own_message(): void
    {
        Route::middleware('api')->post('/api/v1/_test/domain', function (): never {
            throw new DomainException('This repayment has already been approved.');
        });

        $this->postJson('/api/v1/_test/domain')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This repayment has already been approved.');
    }

    #[Test]
    public function a_financial_integrity_violation_returns_a_conflict(): void
    {
        Route::middleware('api')->post('/api/v1/_test/integrity', function (): never {
            throw FinancialIntegrityException::unbalanced('1000.00', '999.00');
        });

        $this->postJson('/api/v1/_test/integrity')
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function internal_errors_do_not_leak_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        Route::middleware('api')->get('/api/v1/_test/boom', function (): never {
            throw new RuntimeException('Connection string user=root password=hunter2');
        });

        $response = $this->getJson('/api/v1/_test/boom')->assertStatus(500);

        $body = $response->getContent() ?: '';

        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);

        // The operator is given the correlation ID so support can find the
        // full trace without it being exposed over the wire.
        $this->assertStringContainsString(
            $response->headers->get(AssignCorrelationId::HEADER) ?? 'missing',
            $response->json('message'),
        );
    }

    #[Test]
    public function an_unknown_endpoint_returns_a_json_not_found(): void
    {
        $this->getJson('/api/v1/no-such-endpoint')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The requested endpoint does not exist.');
    }

    #[Test]
    public function every_response_carries_a_correlation_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $correlationId = $response->headers->get(AssignCorrelationId::HEADER);

        $this->assertNotNull($correlationId);
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}$/', $correlationId);
    }

    #[Test]
    public function a_client_supplied_correlation_id_is_honoured(): void
    {
        $response = $this->getJson('/api/v1/health', [
            AssignCorrelationId::HEADER => 'admin-web-7f3a91',
        ]);

        $this->assertSame(
            'admin-web-7f3a91',
            $response->headers->get(AssignCorrelationId::HEADER),
        );
    }

    #[Test]
    public function a_malformed_correlation_id_is_replaced_rather_than_echoed(): void
    {
        // An unbounded or injected header would otherwise end up in every log
        // line and audit row for the request.
        $response = $this->getJson('/api/v1/health', [
            AssignCorrelationId::HEADER => "bad\nvalue: injected",
        ]);

        $returned = $response->headers->get(AssignCorrelationId::HEADER);

        $this->assertNotSame("bad\nvalue: injected", $returned);
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}$/', (string) $returned);
    }

    #[Test]
    public function an_over_long_correlation_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/health', [
            AssignCorrelationId::HEADER => str_repeat('a', 200),
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f\-]{36}$/',
            (string) $response->headers->get(AssignCorrelationId::HEADER),
        );
    }

    #[Test]
    public function security_headers_are_applied(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));

        // Financial data must not sit in a shared or browser cache.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertNull($response->headers->get('X-Powered-By'));
    }
}
