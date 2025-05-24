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
        // ✅ Avoid route registration when running in CLI (composer, artisan)
        if (!$this->app->runningInConsole()) {
            $this->registerRoutes();
        }

        $this->loadMigrationsFrom(__DIR__ . '/Migrations');

        $configPath = function_exists('config_path')
            ? config_path('bukukwasiku.php')
            : base_path('config/bukukwasiku.php');

        $migrationPath = function_exists('database_path')
            ? database_path('migrations')
            : base_path('database/migrations');

        $this->publishes([
            __DIR__ . '/Config/bukukwasiku.php' => $configPath,
        ], 'bukukwasiku-config');

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
        // ✅ Extra check, though already guarded in boot()
        if ($this->app->runningInConsole()) {
            return;
        }

        Route::prefix('api')
            ->middleware('api')
            ->group(__DIR__ . '/Presentation/Http/Routes/api.php');
    }
}
