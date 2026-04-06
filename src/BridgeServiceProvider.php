<?php

namespace Nextpointer\Bridge;

use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge το config για να είναι προσβάσιμο μέσω config('bridge')
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        // Bind την Engine ως Singleton
        $this->app->singleton(SyncEngine::class, function ($app) {
            return new SyncEngine();
        });
        
        // Alias για το Facade
        $this->app->alias(SyncEngine::class, 'bridge-engine');
    }

    public function boot()
    {
        // 1. Αυτόματη φόρτωση των Migrations από το πακέτο
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            
            // 2. Δυνατότητα για Publish του Config
            $this->publishes([
                __DIR__.'/../config/bridge.php' => config_path('bridge.php'),
            ], 'bridge-config');

            // 3. (Προαιρετικά) Δυνατότητα για Publish των Migrations 
            // αν ο χρήστης θέλει να τα πειράξει χειροκίνητα
            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations')
            ], 'bridge-migrations');
        }
    }
}