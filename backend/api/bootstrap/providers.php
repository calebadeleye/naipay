<?php

declare(strict_types=1);

use App\Domains\Identity\Providers\IdentityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\RateLimitServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    RateLimitServiceProvider::class,
];
