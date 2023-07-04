<?php

namespace Shadowbane\GelfLogger;

use DateTimeZone;
use Gelf\Publisher;
use Gelf\PublisherInterface;
use Gelf\Transport\TcpTransport;
use Gelf\Transport\UdpTransport;
use InvalidArgumentException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\TagProcessor;
use Shadowbane\GelfLogger\Formatters\CustomGelfFormatter;

class GelfLogger
{
    public array $config;

    /**
     * Create the Gelf Logger.
     *
     * @param array $config [
     *      via,
     *      formatter,
     *      level,
     *      transport,
     *      host,
     *      port,
     *      processors,
     * ]
     *
     * @return Logger
     */
    public function __invoke(array $config): Logger
    {
        $this->config = $config;

        $gelfHandler = $this->createHandler();

        //        ray($this->getProcessors());

        $logger = (new Logger(
            name: 'gelf',
            handlers: [$gelfHandler],
            processors: $this->getProcessors(),
            timezone: (new DateTimeZone('UTC')),
        ));

        return $logger;
    }

    /**
     * Create new instance of Gelf handler.
     *
     * @return AbstractProcessingHandler
     */
    private function createHandler(): AbstractProcessingHandler
    {
        if (!isset($this->config['handler']) || blank($this->config['handler'])) {
            $this->config['handler'] = GelfHandler::class;
        }

        $handler = new $this->config['handler'](
            publisher: $this->getPublisher(),
            level: $this->getLevel(),
            bubble: true,
        );

        $handler->setFormatter($this->getFormatter());

        return $handler;
    }

    /**
     * @return FormatterInterface
     */
    private function getFormatter(): FormatterInterface
    {
        if (blank($this->config['formatter'])) {
            $this->config['formatter'] = CustomGelfFormatter::class;
        }

        return new $this->config['formatter']();
    }

    /**
     * @return PublisherInterface
     */
    private function getPublisher(): PublisherInterface
    {
        return match ($this->getTransport()) {
            'tcp' => new Publisher(
                new TcpTransport(
                    $this->getHost(),
                    $this->getPort(),
                ),
            ),
            'udp' => new Publisher(
                new UdpTransport(
                    $this->getHost(),
                    $this->getPort(),
                ),
            ),
            default => throw new InvalidArgumentException('Invalid transport type'),
        };
    }

    /**
     * @return array
     */
    private function getProcessors(): array
    {
        $processorArray = collect($this->config['processors'] ?? []);

        $tagProcessor = $processorArray->where('processor', TagProcessor::class);

        if ($processorArray->where('processor', TagProcessor::class)->count() > 0) {
            $processorArray = $processorArray
                ->map(function ($processor) {
                    if (
                        $processor === TagProcessor::class
                        || (isset($processor['processor']) && $processor['processor'] === TagProcessor::class)
                    ) {
                        $tags = $processor['with']['tags'] ?? [];
                        $tags[] = 'glfapp';
                        $processor['with'] = [
                            'tags' => array_unique($tags),
                        ];
                    }

                    return $processor;
                });
        } else {
            $processorArray->push([
                'processor' => TagProcessor::class,
                'with' => [
                    'tags' => [
                        'glfapp',
                    ],
                ],
            ]);
        }

        return $processorArray->map(fn ($processor) => app()->make($processor['processor'] ?? $processor, $processor['with'] ?? []))
            ->toArray();
    }

    /**
     * @return string
     */
    private function getHost(): string
    {
        return $this->config['host'];
    }

    /**
     * @return int
     */
    private function getPort(): int
    {
        return $this->config['port'];
    }

    /**
     * @return string
     */
    private function getTransport(): string
    {
        return $this->config['transport'];
    }

    /**
     * Convert level string to Monolog Level.
     *
     * @return Level
     */
    private function getLevel(): Level
    {
        return match ($this->config['level']) {
            'debug', 'Debug', 'DEBUG' => Level::Debug,
            'notice', 'Notice', 'NOTICE' => Level::Notice,
            'warning', 'Warning', 'WARNING' => Level::Warning,
            'error', 'Error', 'ERROR' => Level::Error,
            'critical', 'Critical', 'CRITICAL' => Level::Critical,
            'alert', 'Alert', 'ALERT' => Level::Alert,
            'emergency', 'Emergency', 'EMERGENCY' => Level::Emergency,
            default => Level::Info,
        };
    }
}
