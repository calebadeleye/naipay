<?php

declare(strict_types=1);

use App\Domains\Identity\Models\Staff;
use App\Domains\Investors\Models\Investor;
use App\Domains\Merchants\Models\Merchant;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'staff'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'staff'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    /*
     * Naipay is API-only and stateless: administrative clients authenticate
     * with Sanctum bearer tokens, so there is no session guard.
     *
     * `investor` and `merchant` are deliberately separate guards: Sanctum
     * validates a token's provider against the guard it is presented to (see
     * Guard::hasValidProvider()), so an investor's read-only token or a
     * merchant's self-service token can never satisfy an `auth:staff` route
     * no matter how routing evolves, and vice versa.
     */
    'guards' => [
        'staff' => [
            'driver' => 'sanctum',
            'provider' => 'staff',
        ],
        'investor' => [
            'driver' => 'sanctum',
            'provider' => 'investor',
        ],
        'merchant' => [
            'driver' => 'sanctum',
            'provider' => 'merchant',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'staff' => [
            'driver' => 'eloquent',
            'model' => Staff::class,
        ],
        'investor' => [
            'driver' => 'eloquent',
            'model' => Investor::class,
        ],
        'merchant' => [
            'driver' => 'eloquent',
            'model' => Merchant::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    /*
     * Naipay issues and validates reset tokens itself, in PasswordService, so
     * this broker is configured for completeness rather than used directly.
     */
    'passwords' => [
        'staff' => [
            'provider' => 'staff',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
