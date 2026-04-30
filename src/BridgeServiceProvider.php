<?php

namespace Nextpointer\Bridge;

use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register()
    {
        // Merge το config από τον φάκελο config που βρίσκεται έξω από το src
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        // Singleton για τον SyncEngine
        $this->app->singleton(SyncEngine::class, function ($app) {
            return new SyncEngine();
        });

        // Alias για το Facade
        $this->app->alias(SyncEngine::class, 'bridge-engine');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        // 1. Αυτόματο φόρτωμα migrations (από το src/database/migrations)
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        if ($this->app->runningInConsole()) {

            // Ορισμός των διαδρομών
            $configPath    = __DIR__.'/../config/bridge.php';
            $migrationPath = __DIR__.'/database/migrations';
            $resourcePath  = __DIR__.'/Resources';
            $providerPath  = __DIR__.'/Providers';

            // 2. Μεμονωμένα Tags (για επιλεκτικό publish)

            // Config
            $this->publishes([
                $configPath => config_path('bridge.php'),
            ], 'bridge-config');

            // Migrations
            $this->publishes([
                $migrationPath => database_path('migrations'),
            ], 'bridge-migrations');

            // Resources (Demo κτλ) -> app/Sync/Resources
            $this->publishes([
                $resourcePath => app_path('Sync/Resources'),
            ], 'bridge-resources');

            // Providers (PrestaProvider κτλ) -> app/Sync/Providers
            $this->publishes([
                $providerPath => app_path('Sync/Providers'),
            ], 'bridge-providers');

            // 3. Καθολικό Tag: "bridge-all"
            // Δημοσιεύει τα πάντα με μία εντολή
            $this->publishes([
                $configPath    => config_path('bridge.php'),
                $migrationPath => database_path('migrations'),
                $resourcePath  => app_path('Sync/Resources'),
                $providerPath  => app_path('Sync/Providers'),
            ], 'bridge-all');
        }
    }
}
