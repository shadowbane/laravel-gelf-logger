<?php

namespace Shadowbane\GelfLogger;

use Gelf\PublisherInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler that publishes log records to a GELF-compatible server.
 *
 * This handler receives formatted GELF messages from the formatter
 * and publishes them via the configured transport (UDP or TCP).
 */
class GelfHandler extends AbstractProcessingHandler
{
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
     * Publish the formatted GELF message to the configured server.
     *
     * @param  LogRecord  $record  The log record containing the formatted GELF message
     */
    protected function write(LogRecord $record): void
    {
        $this->publisher->publish($record->formatted);
    }
}
