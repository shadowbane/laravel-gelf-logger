<?php

namespace Shadowbane\GelfLogger\Providers;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Shadowbane\GelfLogger\Commands\SendException;
use Shadowbane\GelfLogger\GelfLogger;

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

        // Buffered logs are flushed at PHP shutdown, which a queue worker
        // reaches only when it exits. Flush after every job instead, and on
        // terminate for HTTP and console runs.
        $this->app['events']->listen(
            [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class],
            fn () => GelfLogger::flushBuffers(),
        );
        $this->app->terminating(fn () => GelfLogger::flushBuffers());
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
