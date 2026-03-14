<?php

namespace Shadowbane\GelfLogger\Formatters;

use Gelf\Message;
use Monolog\Formatter\GelfMessageFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;
use Throwable;

/**
 * Custom GELF message formatter that extends Monolog's GelfMessageFormatter.
 *
 * Adds additional fields to every GELF message:
 * - `service`: The application name from Laravel config
 * - `hostname`: The server hostname
 * - `log_status`: The human-readable log level name
 * - `facility`: The log channel name
 * - `extras`: JSON object containing all custom context and Laravel Context data
 *
 * Processor data (memory, git, web, load, tags) remains as individual top-level fields.
 * Exception data is flattened into individual fields (e.g., `exception_class`, `exception_message`).
 * Everything else (user context + Laravel Context::add() data) is grouped into `extras`.
 */
class CustomGelfFormatter extends GelfMessageFormatter
{
    /**
     * Keys that are handled separately and should not be included in the extras field.
     *
     * @var array<int, string>
     */
    private const RESERVED_CONTEXT_KEYS = ['exception', 'exception_trace'];

    /**
     * Known keys added by Monolog processors.
     * These stay as individual top-level GELF fields.
     * Anything in `extra` not matching these keys is treated as Laravel Context data
     * and gets grouped into the `extras` JSON field.
     *
     * @var array<int, string>
     */
    private const PROCESSOR_KEYS = [
        // GitProcessor
        'git',
        // MemoryUsageProcessor
        'memory_usage',
        // MemoryPeakUsageProcessor
        'memory_peak_usage',
        // LoadAverageProcessor
        'load_average',
        // WebProcessor
        'url',
        'ip',
        'http_method',
        'server',
        'referrer',
        // TagProcessor
        'tags',
    ];

    /**
     * Format a log record into a GELF Message.
     *
     * @param  LogRecord  $record  The log record to format
     *
     * @return Message The formatted GELF message ready for publishing
     */
    public function format(LogRecord $record): Message
    {
        /** @var array<string, mixed> $context */
        $context = $this->normalize($record->context);

        /** @var array<string, mixed> $extra */
        $extra = $this->normalize($record->extra);

        $this->handleExceptions($context, $record->context);

        $message = new Message();
        $message
            ->setTimestamp($record->datetime)
            ->setShortMessage($record->message)
            ->setHost($this->systemName)
            ->setLevel($this->getGraylog2Priority($record->level));

        $this->setAdditionalData($message, $record->level);

        // message length + system name length + 200 for padding / metadata
        $len = 200 + strlen($record->message) + strlen($this->systemName);

        if ($len > $this->maxLength) {
            $message->setShortMessage(Utils::substr($record->message, 0, $this->maxLength));
        }

        if (isset($record->channel)) {
            $message->setAdditional('facility', $record->channel);
        }

        // Split extra into processor data (top-level) and Laravel Context data (into extras)
        $contextExtras = [];
        foreach ($extra as $key => $val) {
            if ($this->isProcessorKey($key)) {
                // Processor data stays as individual top-level fields
                $val = is_scalar($val) || null === $val ? $val : $this->toJson($val);
                $len = strlen($this->extraPrefix.$key.$val);
                if ($len > $this->maxLength) {
                    $message->setAdditional($this->extraPrefix.$key, Utils::substr((string) $val, 0, $this->maxLength));
                } else {
                    $message->setAdditional($this->extraPrefix.$key, $val);
                }
            } else {
                // Laravel Context data goes into extras
                $contextExtras[$key] = $val;
            }
        }

        // Add exception fields as individual top-level fields
        $this->addExceptionFields($message, $context);

        // Collect user context + Laravel Context into 'extras' JSON field
        $this->addExtrasField($message, $context, $contextExtras);

        return $message;
    }

    /**
     * Check if a key belongs to a known Monolog processor.
     *
     * @param  string  $key  The extra field key
     *
     * @return bool True if the key is from a processor
     */
    private function isProcessorKey(string $key): bool
    {
        return in_array($key, self::PROCESSOR_KEYS, true);
    }

    /**
     * Set standard additional fields on the GELF message.
     *
     * @param  Message  $message  The GELF message to enrich
     * @param  Level  $level  The log level for the record
     */
    private function setAdditionalData(Message $message, Level $level): void
    {
        $message->setAdditional('service', config('app.name'));
        $message->setAdditional('hostname', gethostname());
        $message->setAdditional('log_status', $level->getName());
    }

    /**
     * Add exception-related fields as individual GELF additional fields.
     *
     * @param  Message  $message  The GELF message
     * @param  array<string, mixed>  $context  The normalized context array
     */
    private function addExceptionFields(Message $message, array $context): void
    {
        foreach ($context as $key => $val) {
            if (!str_starts_with($key, 'exception_')) {
                continue;
            }

            $val = is_scalar($val) || null === $val ? $val : $this->toJson($val);
            $len = strlen($key.$val);
            if ($len > $this->maxLength) {
                $message->setAdditional($key, Utils::substr((string) $val, 0, $this->maxLength));

                continue;
            }
            $message->setAdditional($key, $val);
        }

        // Extract file and line from exception data
        if (isset($context['exception']['file']) && !$message->hasAdditional('file')) {
            if (1 === preg_match("/^(.+):(\d+)$/", $context['exception']['file'], $matches)) {
                $message->setAdditional('file', $matches[1]);
                $message->setAdditional('line', $matches[2]);
            }
        }
    }

    /**
     * Collect all non-exception context data and Laravel Context data into a single 'extras' JSON field.
     *
     * Groups user-passed log context (e.g., user, order_id) and Laravel Context::add() data
     * (e.g., trace_id) into one JSON field for clean Grafana dashboard handling.
     *
     * @param  Message  $message  The GELF message
     * @param  array<string, mixed>  $context  The normalized context array
     * @param  array<string, mixed>  $contextExtras  Laravel Context data extracted from extra
     */
    private function addExtrasField(Message $message, array $context, array $contextExtras = []): void
    {
        $extras = [];

        // Add user-passed context (non-exception, non-reserved)
        foreach ($context as $key => $val) {
            if (str_starts_with($key, 'exception_') || in_array($key, self::RESERVED_CONTEXT_KEYS, true)) {
                continue;
            }

            $extras[$key] = $val;
        }

        // Merge Laravel Context data (trace_id, etc.)
        $extras = array_merge($extras, $contextExtras);

        if (!empty($extras)) {
            $json = $this->toJson($extras);
            if (strlen('extras'.$json) > $this->maxLength) {
                $message->setAdditional('extras', Utils::substr($json, 0, $this->maxLength));
            } else {
                $message->setAdditional('extras', $json);
            }
        }
    }

    /**
     * Translate Monolog log levels to Graylog2/syslog priorities.
     *
     * @param  Level  $level  The Monolog log level
     *
     * @return int The syslog priority (0 = Emergency, 7 = Debug)
     */
    private function getGraylog2Priority(Level $level): int
    {
        return match ($level) {
            Level::Debug => 7,
            Level::Info => 6,
            Level::Notice => 5,
            Level::Warning => 4,
            Level::Error => 3,
            Level::Critical => 2,
            Level::Alert => 1,
            Level::Emergency => 0,
        };
    }

    /**
     * Extract and flatten exception data from the context array.
     *
     * Converts nested exception data into prefixed keys (e.g., `exception_class`,
     * `exception_message`) and extracts the stack trace if available.
     *
     * @param  array<string, mixed>  &$context  The normalized context array (modified in place)
     * @param  array<string, mixed>  $contextRecord  The original context from the log record
     */
    private function handleExceptions(array &$context, mixed $contextRecord): void
    {
        if (isset($context['exception'])) {
            if (isset($context['trace'])) {
                /** @var Throwable $throwable */
                $throwable = $contextRecord['exception'];
                $context['exception_trace'] = $throwable->getTraceAsString();

                unset($context['trace'], $context['exception']['trace']);
            }

            foreach ($context['exception'] as $key => $value) {
                $context['exception_'.$key] = $value;
            }

            unset($context['exception']);
        }
    }
}
