<?php

namespace Shadowbane\GelfLogger;

use Gelf\PublisherInterface;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog handler that publishes log records to a GELF-compatible server.
 *
 * This handler receives formatted GELF messages from the formatter
 * and publishes them via the configured transport (UDP or TCP).
 *
 * Logging must never break the application: a transport failure (Graylog
 * down, unreachable, slow) is caught and reported to the optional error
 * channel instead of being thrown into the caller.
 */
class GelfHandler extends AbstractProcessingHandler
{
    /**
     * Laravel log channel that receives delivery failures, or null to drop them.
     */
    protected ?string $errorChannel = null;

    /**
     * Set while a failure is being reported, so an error channel that routes
     * back to this handler cannot recurse.
     */
    private static bool $reporting = false;

    /**
     * @param  PublisherInterface  $publisher  The GELF publisher that sends messages to the server
     * @param  int|string|Level  $level  The minimum logging level to trigger this handler
     * @param  bool  $bubble  Whether messages that are handled should bubble up the stack
     */
    public function __construct(
        protected PublisherInterface $publisher,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Set the Laravel log channel that receives delivery failures.
     *
     * @param  string|null  $channel  Channel name, or null to drop failures silently
     */
    public function setErrorChannel(?string $channel): static
    {
        $this->errorChannel = $channel;

        return $this;
    }

    /**
     * Publish the formatted GELF message to the configured server.
     *
     * @param  LogRecord  $record  The log record containing the formatted GELF message
     */
    protected function write(LogRecord $record): void
    {
        try {
            $this->publisher->publish($record->formatted);
        } catch (Throwable $e) {
            $this->reportFailure('Failed to send a log record to Graylog: '.$e->getMessage(), $e);
        }
    }

    /**
     * Publish several records, as BufferHandler does when it flushes.
     *
     * Stops at the first failure: when Graylog is unreachable every further
     * record would wait out its own connect timeout, turning one outage into
     * a long stall at the end of the request.
     *
     * @param  LogRecord[]  $records
     */
    public function handleBatch(array $records): void
    {
        $pending = array_values(array_filter($records, fn (LogRecord $record) => $this->isHandling($record)));

        foreach ($pending as $index => $record) {
            try {
                $record = $this->processRecord($record);
                $record->formatted = $this->getFormatter()->format($record);
                $this->publisher->publish($record->formatted);
            } catch (Throwable $e) {
                $dropped = count($pending) - $index;
                $this->reportFailure(
                    "Failed to send log records to Graylog; dropped {$dropped} of ".count($pending).': '.$e->getMessage(),
                    $e,
                );

                return;
            }
        }
    }

    /**
     * Report a delivery failure to the error channel, if one is configured.
     *
     * @param  string  $message  What failed
     * @param  Throwable  $exception  The underlying error
     */
    protected function reportFailure(string $message, Throwable $exception): void
    {
        if ($this->errorChannel === null || self::$reporting) {
            return;
        }

        self::$reporting = true;

        try {
            Log::channel($this->errorChannel)->critical($message, ['exception' => $exception]);
        } catch (Throwable) {
            // The fallback channel failed too; logging must still not throw.
        } finally {
            self::$reporting = false;
        }
    }
}
