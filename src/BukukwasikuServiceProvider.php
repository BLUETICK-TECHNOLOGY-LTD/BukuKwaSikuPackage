<?php

namespace BukukwasikuPackage;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

use function base_path;
use function config_path;
use function database_path;

class BukukwasikuServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Register routes
        if (!$this->app->runningInConsole()) {
            $this->registerRoutes();
        }

        // Register migrations
        $this->loadMigrationsFrom(__DIR__ . '/Migrations');

        // Fallback for config_path()
        $configPath = function_exists('config_path')
            ? config_path('bukukwasiku.php')
            : base_path('config/bukukwasiku.php');

        // Fallback for database_path()
        $migrationPath = function_exists('database_path')
            ? database_path('migrations')
            : base_path('database/migrations');

        // Publish config
        $this->publishes([
            __DIR__ . '/Config/bukukwasiku.php' => $configPath,
        ], 'bukukwasiku-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/Migrations/' => $migrationPath,
        ], 'bukukwasiku-migrations');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/bukukwasiku.php',
            'bluetick'
        );
    }

    protected function registerRoutes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/Presentation/Http/Routes/api.php');
    }
}
