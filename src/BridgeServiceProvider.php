<?php

namespace Nextpointer\Bridge;

use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge το config για να είναι προσβάσιμο μέσω config('bridge')
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        $this->app->singleton(SyncEngine::class, function ($app) {
            return new SyncEngine();
        });

        // Alias για το Facade
        $this->app->alias(SyncEngine::class, 'bridge-engine');
    }

   public function boot()
{
    // Επειδή ο Provider είναι στο src/ και τα migrations στο src/database/migrations
    $this->loadMigrationsFrom(__DIR__.'/database/migrations');

    if ($this->app->runningInConsole()) {
        // Αντίστοιχη διόρθωση και για το publish
        $this->publishes([
            __DIR__.'/database/migrations' => database_path('migrations'),
        ], 'bridge-migrations');

        $this->publishes([
            __DIR__.'/../config/bridge.php' => config_path('bridge.php'),
        ], 'bridge-config');

        $this->publishes([
            __DIR__.'/Resources/DemoResource.php' => app_path('Sync/Resources/DemoResource.php'),
        ], 'bridge-resources');
    }
}
}
