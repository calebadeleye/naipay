<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\Staff;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a staff member from using the system until they have completed the
 * security steps their account requires — changing an issued password, or
 * enrolling in two-factor where their role makes it mandatory.
 *
 * Applied to every authenticated route except the handful needed to complete
 * those steps, so an operator is never locked out of the remedy itself.
 */
final class EnsureSecurityStepsCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->user();

        if (! $staff instanceof Staff) {
            return $next($request);
        }

        if ($staff->must_change_password) {
            return ApiResponse::error(
                'You must change your password before continuing.',
                status: Response::HTTP_FORBIDDEN,
            )->withHeaders(['X-Naipay-Required-Action' => 'change_password']);
        }

        if ($staff->requiresTwoFactor() && ! $staff->hasTwoFactorEnabled()) {
            return ApiResponse::error(
                'Two-factor authentication is mandatory for your role. Set it up to continue.',
                status: Response::HTTP_FORBIDDEN,
            )->withHeaders(['X-Naipay-Required-Action' => 'enrol_two_factor']);
        }

        return $next($request);
    }
}
