<?php

declare(strict_types=1);

use App\Support\Exceptions\ApiExceptionRenderer;
use App\Support\Http\Middleware\ApplySecurityHeaders;
use App\Support\Http\Middleware\AssignCorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Versioned from the outset. The merchant portal and mobile app will
        // mount alongside the admin surface under the same version prefix.
        api: __DIR__.'/../routes/api_v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AssignCorrelationId::class,
            ApplySecurityHeaders::class,
        ]);

        // Sanctum issues stateless bearer tokens for the admin client, so no
        // stateful cookie domain is configured. Adding one later for a
        // first-party SPA does not change the token path.
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // The API never issues a redirect to a login page; unauthenticated
        // requests must surface as 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request),
        );

        // Credentials and identity numbers must never reach a log file, even
        // inside an exception's request context.
        $exceptions->dontFlash(config('naipay.security.redacted_keys', []));
    })
    ->create();
