<?php

namespace Shadowbane\GelfLogger\Formatters;

use Gelf\Message;
use Monolog\Formatter\GelfMessageFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;
use Throwable;

class CustomGelfFormatter extends GelfMessageFormatter
{
    /**
     * Formats a log record.
     *
     * @param  LogRecord $record A record to format
     *
     * @return mixed     The formatted record
     */
    public function format(LogRecord $record): Message
    {
        $context = $extra = [];
        if (isset($record->context)) {
            /** @var array $context */
            $context = $this->normalize($record->context);
        }
        if (isset($record->extra)) {
            /** @var array $extra */
            $extra = $this->normalize($record->extra);
        }

        $this->handleExceptions($context, $record->context);

        $message = new Message();
        $message
            ->setTimestamp($record->datetime)
            ->setShortMessage($record->message)
            ->setHost($this->systemName)
            ->setLevel($this->getGraylog2Priority($record->level));

        // add additional data
        $this->setAdditionalData($message, $record->level);

        // message length + system name length + 200 for padding / metadata
        $len = 200 + strlen($record->message) + strlen($this->systemName);

        if ($len > $this->maxLength) {
            $message->setShortMessage(Utils::substr($record->message, 0, $this->maxLength));
        }

        if (isset($record->channel)) {
            $message->setAdditional('facility', $record->channel);
        }

        foreach ($extra as $key => $val) {
            $val = is_scalar($val) || null === $val ? $val : $this->toJson($val);
            $len = strlen($this->extraPrefix.$key.$val);
            if ($len > $this->maxLength) {
                $message->setAdditional($this->extraPrefix.$key, Utils::substr((string) $val, 0, $this->maxLength));

                continue;
            }
            $message->setAdditional($this->extraPrefix.$key, $val);
        }

        foreach ($context as $key => $val) {
            $val = is_scalar($val) || null === $val ? $val : $this->toJson($val);
            $len = strlen($key.$val);
            if ($len > $this->maxLength) {
                $message->setAdditional($key, Utils::substr((string) $val, 0, $this->maxLength));

                continue;
            }
            $message->setAdditional($key, $val);
        }

        if (isset($context['exception']['file']) && !$message->hasAdditional('file')) {
            if (1 === preg_match("/^(.+):(\d+)$/", $context['exception']['file'], $matches)) {
                $message->setAdditional('file', $matches[1]);
                $message->setAdditional('line', $matches[2]);
            }
        }

        return $message;
    }

    private function setAdditionalData(Message $message, Level $level): void
    {
        $message->setAdditional('service', config('app.name'));
        $message->setAdditional('hostname', gethostname());
        $message->setAdditional('log_status', $level->getName());
    }

    /**
     * Translates Monolog log levels to Graylog2 log priorities.
     *
     * @param Level $level
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
     * @param array $context
     * @param mixed $contextRecord
     *
     * @return void
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
