<?php

namespace Shadowbane\GelfLogger\Exceptions;

use Exception;
use Throwable;

/**
 * Exception used for testing GELF logger connectivity.
 *
 * This exception is thrown by the `gelf:send-test-exception` Artisan command
 * to verify that the GELF logging pipeline is working correctly.
 */
class TestException extends Exception
{
    /**
     * @param  string  $message  The exception message
     * @param  int  $code  The exception code
     * @param  ?Throwable  $previous  The previous throwable used for exception chaining
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
