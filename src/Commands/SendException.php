<?php

namespace Shadowbane\GelfLogger\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Shadowbane\GelfLogger\Exceptions\TestException;

/**
 * Artisan command to test GELF logger connectivity.
 *
 * Sends a test exception to the configured GELF server to verify
 * that the logging pipeline is working correctly.
 */
class SendException extends Command
{
    /** @var string */
    protected $name = 'gelf:send-test-exception';

    /** @var string */
    protected $description = 'Send a test exception to the GELF logger to verify connectivity';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            throw new TestException('This is a test exception. Sent at: '.date('Y-m-d H:i:s'), 500);
        } catch (TestException $e) {
            Log::channel('gelf')->error($e->getMessage(), [
                'exception' => $e,
                'test' => true,
                'sent_at' => now()->toIso8601String(),
            ]);

            $this->info('Test exception sent to GELF logger successfully.');

            return self::SUCCESS;
        }
    }
}
