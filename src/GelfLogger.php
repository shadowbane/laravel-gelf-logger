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
use Monolog\Handler\BufferHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\TagProcessor;
use Shadowbane\GelfLogger\Formatters\CustomGelfFormatter;
use WeakMap;

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
     * Buffers created by this factory, so long-running processes can flush
     * them between units of work. Weakly held: a forgotten channel is freed.
     *
     * @var WeakMap<BufferHandler, true>|null
     */
    private static ?WeakMap $buffers = null;

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
     *     buffer?: bool|string,
     *     timeout?: int|string,
     *     error?: string|false|null,
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
            handlers: [$this->wrapInBuffer($this->createHandler())],
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

        if (method_exists($handler, 'setErrorChannel')) {
            $errorChannel = $this->config['error'] ?? null;
            $handler->setErrorChannel(blank($errorChannel) || $errorChannel === false ? null : (string) $errorChannel);
        }

        return $handler;
    }

    /**
     * Wrap the handler in a buffer that sends its records in one go.
     *
     * Records are held in memory and published when the buffer is flushed:
     * at PHP shutdown (after the response on php-fpm and FrankenPHP), after
     * each queued job, or early if a single request logs 1000 records.
     *
     * @param  AbstractProcessingHandler  $handler  The configured GELF handler
     *
     * @return HandlerInterface
     */
    private function wrapInBuffer(AbstractProcessingHandler $handler): HandlerInterface
    {
        if (!filter_var($this->config['buffer'] ?? true, FILTER_VALIDATE_BOOL)) {
            return $handler;
        }

        $buffer = new BufferHandler($handler, 1000, $this->getLevel(), true, true);

        self::$buffers ??= new WeakMap();
        self::$buffers[$buffer] = true;

        return $buffer;
    }

    /**
     * Send everything currently buffered.
     */
    public static function flushBuffers(): void
    {
        foreach (self::$buffers ?? [] as $buffer => $_) {
            $buffer->flush();
        }
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
            'tcp' => new Publisher($this->createTcpTransport()),
            'udp' => new Publisher(new UdpTransport($this->getHost(), $this->getPort())),
            default => throw new InvalidArgumentException("Invalid GELF transport type [{$this->getTransport()}]. Supported: tcp, udp."),
        };
    }

    /**
     * Create the TCP transport with a bounded connect timeout.
     *
     * @return TcpTransport
     */
    private function createTcpTransport(): TcpTransport
    {
        $transport = new TcpTransport($this->getHost(), $this->getPort());

        // gelf-php waits 30 seconds by default, which stalls a request for as
        // long whenever Graylog is unreachable. Whole seconds only.
        $transport->setConnectTimeout(max(1, (int) ($this->config['timeout'] ?? 2)));

        return $transport;
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
                // Config is user-written, so the documented shape is not guaranteed.
                // @phpstan-ignore nullCoalesce.offset
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
