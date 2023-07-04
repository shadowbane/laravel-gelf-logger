<?php

namespace Shadowbane\GelfLogger\Commands;

use Exception;
use Illuminate\Console\Command;
use Throwable;

class TestException extends Exception
{
    /**
     * @param string  $message
     * @param int  $code
     * @param Throwable|null  $previous
     */
    public function __construct(string $message = '', int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class SendException extends Command
{
    protected $name = 'gelf:send-test-exception';

    protected $description = 'Test sending exception to gelf logger';

    /**
     * Execute the console command.
     *
     * @throws TestException
     */
    public function handle(): int
    {
        throw new TestException('This is test exception. Sent at: '.date('Y-m-d H:i:s'), 500);
    }
}
