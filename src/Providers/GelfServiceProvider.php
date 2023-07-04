<?php

namespace Shadowbane\GelfLogger\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Class GelfServiceProvider.
 *
 * @extends ServiceProvider
 */
class GelfServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->commands([
            \Shadowbane\GelfLogger\Commands\SendException::class,
        ]);
    }

    /**
     * Register laravel-gelf-logger,
     * merge configuration into the logging.channels array.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/logging.php',
            'logging.channels'
        );
    }
}
