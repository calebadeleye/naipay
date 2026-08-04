<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerDomainMigrations();
        $this->registerFactoryResolution();
        $this->configureModels();
        $this->configurePasswordPolicy();
        $this->configureUrls();
        $this->guardAgainstSlowQueries();
    }

    /**
     * Each domain owns its migrations, so they live beside the domain rather
     * than in one shared directory.
     *
     * Laravel sorts migrations by filename across every registered path, so
     * cross-domain foreign keys resolve correctly as long as the timestamp
     * prefixes reflect the real dependency order.
     */
    private function registerDomainMigrations(): void
    {
        $paths = glob(app_path('Domains/*/Database/Migrations'), GLOB_ONLYDIR) ?: [];

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }

    /**
     * Teaches Eloquent where factories live for domain models.
     *
     * The default convention maps `App\Models\Loan` to
     * `Database\Factories\LoanFactory`. Naipay's models sit under
     * `App\Domains\<Domain>\Models`, which the default would resolve to
     * `Database\Factories\Domains\<Domain>\Models\LoanFactory`. Factories stay
     * flat in `database/factories`, so only the class name matters.
     */
    private function registerFactoryResolution(): void
    {
        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    /**
     * Eloquent is configured to fail loudly rather than silently.
     *
     * Lazy loading in particular has a habit of turning a portfolio report into
     * thousands of queries; catching it in development is far cheaper than
     * discovering it under production load.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Mass assignment stays guarded everywhere. Financial models must
        // declare exactly what a request is permitted to set.
        Model::unguard(false);
    }

    /**
     * Baseline password policy for staff accounts.
     *
     * These users hold access to merchant identity data and the ledger, so the
     * floor is higher than a consumer application would set.
     */
    private function configurePasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            // Checking against known breach corpora requires an outbound call,
            // which is not appropriate in tests or offline development.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    private function configureUrls(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    /**
     * Surfaces queries that would degrade the administrative interface.
     *
     * Logged rather than thrown: a slow report should still return, but it
     * should not go unnoticed.
     */
    private function guardAgainstSlowQueries(): void
    {
        if ($this->app->isProduction() || $this->app->runningUnitTests()) {
            return;
        }

        DB::whenQueryingForLongerThan(2_000, function (): void {
            logger()->warning('A database query exceeded the 2 second budget.');
        });
    }
}
