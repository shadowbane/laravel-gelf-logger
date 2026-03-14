<?php

namespace Shadowbane\GelfLogger\Providers;

use Illuminate\Support\ServiceProvider;
use Shadowbane\GelfLogger\Commands\SendException;

/**
 * Service provider for the Laravel GELF Logger package.
 *
 * Registers the GELF logging channel configuration and
 * the test Artisan command for verifying connectivity.
 */
class GelfServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the package services.
     *
     * Registers the Artisan command and publishable config file.
     */
    public function boot(): void
    {
        $this->commands([
            SendException::class,
        ]);

        $this->publishes([
            __DIR__.'/../../config/logging.php' => config_path('gelf-logger.php'),
        ], 'gelf-logger-config');
    }

    /**
     * Register the package services.
     *
     * Merges the GELF logging channel into Laravel's logging configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/logging.php',
            'logging.channels'
        );
    }
}
