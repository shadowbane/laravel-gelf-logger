<?php

use Monolog\Processor\GitProcessor;
use Monolog\Processor\LoadAverageProcessor;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\WebProcessor;
use Shadowbane\GelfLogger\Formatters\CustomGelfFormatter;
use Shadowbane\GelfLogger\GelfLogger;

return [
    'gelf' => [
        'driver' => 'custom',
        'via' => GelfLogger::class,
        'formatter' => CustomGelfFormatter::class,

        // should respect \Monolog\Level
        'level' => env('GELF_LOGGER_LEVEL', 'warning'),
        'transport' => env('GELF_LOGGER_TRANSPORT', 'udp'),
        'host' => env('GELF_LOGGER_HOST', '127.0.0.1'),
        'port' => env('GELF_LOGGER_PORT', 12201),

        // additional processors
        'processors' => [
            GitProcessor::class,
            MemoryUsageProcessor::class,
            MemoryPeakUsageProcessor::class,
            LoadAverageProcessor::class,
            WebProcessor::class,
        ],
    ],
];
