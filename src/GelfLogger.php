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

/**
 * Factory class that creates a configured Monolog Logger for GELF output.
 *
 * This class is invoked by Laravel's logging system via the `via` config key.
 * It assembles the full logging pipeline: transport → publisher → handler → formatter → processors.
 *
 * @see GelfHandler
 * @see CustomGelfFormatter
 */
class GelfLogger
{
    /**
     * The logging channel configuration.
     *
     * @var array{
     *     driver: string,
     *     via: class-string,
     *     formatter?: class-string<FormatterInterface>,
     *     handler?: class-string<AbstractProcessingHandler>,
     *     level: string,
     *     transport: string,
     *     host: string,
     *     port: int,
     *     tags?: array<int, string>,
     *     processors?: array<int, class-string|array{processor: class-string, with?: array<string, mixed>}>,
     * }
     */
    public array $config;

    /**
     * Create the GELF Logger.
     *
     * @param  array{
     *     driver: string,
     *     via: class-string,
     *     formatter?: class-string<FormatterInterface>,
     *     handler?: class-string<AbstractProcessingHandler>,
     *     level: string,
     *     transport: string,
     *     host: string,
     *     port: int,
     *     processors?: array<int, class-string|array{processor: class-string, with?: array<string, mixed>}>,
     * }  $config  The logging channel configuration array
     *
     * @throws InvalidArgumentException If required config keys are missing or transport is invalid
     *
     * @return Logger
     */
    public function __invoke(array $config): Logger
    {
        $this->config = $config;
        $this->validateConfig();

        return new Logger(
            name: 'gelf',
            handlers: [$this->createHandler()],
            processors: $this->getProcessors(),
            timezone: new DateTimeZone('UTC'),
        );
    }

    /**
     * Validate that required configuration keys are present.
     *
     * @throws InvalidArgumentException If a required config key is missing
     */
    private function validateConfig(): void
    {
        $required = ['host', 'port', 'transport'];

        foreach ($required as $key) {
            if (!isset($this->config[$key]) || blank($this->config[$key])) {
                throw new InvalidArgumentException("GELF logger config key [{$key}] is required.");
            }
        }
    }

    /**
     * Create a new instance of the GELF handler with formatter attached.
     *
     * @return AbstractProcessingHandler
     */
    private function createHandler(): AbstractProcessingHandler
    {
        $handlerClass = $this->config['handler'] ?? GelfHandler::class;

        if (blank($handlerClass)) {
            $handlerClass = GelfHandler::class;
        }

        /** @var AbstractProcessingHandler $handler */
        $handler = new $handlerClass(
            publisher: $this->getPublisher(),
            level: $this->getLevel(),
            bubble: true,
        );

        $handler->setFormatter($this->getFormatter());

        return $handler;
    }

    /**
     * Create the formatter instance for GELF messages.
     *
     * @return FormatterInterface
     */
    private function getFormatter(): FormatterInterface
    {
        $formatterClass = $this->config['formatter'] ?? CustomGelfFormatter::class;

        if (blank($formatterClass)) {
            $formatterClass = CustomGelfFormatter::class;
        }

        return new $formatterClass();
    }

    /**
     * Create a GELF publisher with the configured transport (TCP or UDP).
     *
     * @throws InvalidArgumentException If the transport type is not 'tcp' or 'udp'
     *
     * @return PublisherInterface
     */
    private function getPublisher(): PublisherInterface
    {
        return match ($this->getTransport()) {
            'tcp' => new Publisher(new TcpTransport($this->getHost(), $this->getPort())),
            'udp' => new Publisher(new UdpTransport($this->getHost(), $this->getPort())),
            default => throw new InvalidArgumentException("Invalid GELF transport type [{$this->getTransport()}]. Supported: tcp, udp."),
        };
    }

    /**
     * Build the array of Monolog processor instances from config.
     *
     * Ensures a TagProcessor is always present with 'glfapp' tag (required by Graylog stream rules).
     * Additional tags from the 'tags' config key are merged in.
     * Processors can be specified as class strings or arrays with 'processor' and 'with' keys.
     *
     * @return array<int, callable>
     */
    private function getProcessors(): array
    {
        $processorArray = collect($this->config['processors'] ?? []);

        // Remove any user-defined TagProcessor — we'll add our own with merged tags
        $processorArray = $processorArray->reject(function ($processor) {
            return $processor === TagProcessor::class
                || (is_array($processor) && ($processor['processor'] ?? null) === TagProcessor::class);
        });

        // Build tags: 'glfapp' is always present, merge with config tags
        $tags = array_unique(array_merge(
            ['glfapp'],
            (array) ($this->config['tags'] ?? []),
        ));

        $processorArray->push([
            'processor' => TagProcessor::class,
            'with' => ['tags' => array_values($tags)],
        ]);

        return $processorArray
            ->map(fn ($processor) => app()->make(
                $processor['processor'] ?? $processor,
                $processor['with'] ?? [],
            ))
            ->toArray();
    }

    /**
     * Get the GELF server hostname from config.
     *
     * @return string
     */
    private function getHost(): string
    {
        return $this->config['host'];
    }

    /**
     * Get the GELF server port from config.
     *
     * @return int
     */
    private function getPort(): int
    {
        return (int) $this->config['port'];
    }

    /**
     * Get the transport protocol from config.
     *
     * @return string Either 'tcp' or 'udp'
     */
    private function getTransport(): string
    {
        return strtolower($this->config['transport']);
    }

    /**
     * Convert the configured level string to a Monolog Level enum.
     *
     * @return Level
     */
    private function getLevel(): Level
    {
        $level = ucfirst(strtolower($this->config['level'] ?? 'info'));

        return Level::fromName($level);
    }
}
