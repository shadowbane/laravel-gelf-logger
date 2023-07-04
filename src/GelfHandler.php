<?php

namespace Shadowbane\GelfLogger;

use Gelf\PublisherInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class GelfHandler extends AbstractProcessingHandler
{
    /**
     * @var PublisherInterface the publisher object that sends the message to the server
     */
    protected PublisherInterface $publisher;

    /**
     * @param PublisherInterface $publisher a gelf publisher object
     * @param int|string|Level $level
     * @param bool $bubble
     */
    public function __construct(PublisherInterface $publisher, int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->publisher = $publisher;
    }

    /**
     * Writes the (already formatted) record down to the log of the implementing handler.
     *
     * @param LogRecord $record
     */
    protected function write(LogRecord $record): void
    {
        $this->publisher->publish($record->formatted);
    }
}
