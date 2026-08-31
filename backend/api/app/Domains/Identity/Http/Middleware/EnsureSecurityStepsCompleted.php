<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\Staff;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a staff member from using the system until they have changed an
 * issued password.
 *
 * Two-factor enrolment is *not* enforced here: an account whose role makes 2FA
 * mandatory can still sign in and work, and is instead nudged by a persistent
 * banner in the console (see the admin app shell). Only the password change,
 * which leaves a shared credential live until it is done, is a hard gate.
 *
 * Applied to every authenticated route except the handful needed to change the
 * password, so an operator is never locked out of the remedy itself.
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

        return $next($request);
    }
}
