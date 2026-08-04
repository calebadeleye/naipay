<?php

declare(strict_types=1);

namespace App\Domains\Identity\Providers;

use App\Domains\Identity\Models\AccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Wiring for the Identity domain.
 *
 * Each domain that needs container or framework configuration registers its
 * own provider, so the application-wide provider does not accumulate every
 * domain's setup.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Naipay's token model adds device provenance and the casts the
        // idle-timeout middleware relies on.
        Sanctum::usePersonalAccessTokenModel(AccessToken::class);
    }
}
