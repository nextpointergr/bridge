<?php
namespace Nextpointer\Bridge;

use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider {
    public function register() {
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');
        $this->app->singleton(SyncEngine::class, fn() => new SyncEngine());
    }

    public function boot() {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/bridge.php' => config_path('bridge.php')], 'bridge-config');
        }
    }
}