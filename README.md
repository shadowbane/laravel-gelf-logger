# Laravel GELF Logger

A custom Laravel Monolog logger for sending logs to Graylog via the GELF (Graylog Extended Log Format) protocol.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Features

- Send Laravel logs directly to Graylog via GELF protocol
- Supports both **UDP** and **TCP** transports
- Automatic exception tracking with stack traces
- Custom log context support with additional GELF fields
- Configurable Monolog processors (Git, Memory, Web, Load Average, Tags)
- Configurable log levels
- Custom GELF formatter with service and hostname metadata
- Laravel auto-discovery support

## Requirements

- PHP ^8.2
- Laravel 11.x or higher
- ext-json
- graylog2/gelf-php ^2.0
- monolog ^3.0

## Installation

Install the package via Composer:

```bash
composer require shadowbane/laravel-gelf-logger
```

The service provider will be automatically registered via Laravel's package auto-discovery.

## Configuration

### Environment Variables

Add the following to your `.env` file:

```env
GELF_LOGGER_HOST=127.0.0.1
GELF_LOGGER_PORT=12201
GELF_LOGGER_TRANSPORT=udp
GELF_LOGGER_LEVEL=warning
GELF_LOGGER_TAGS=
```

#### Configuration Options

| Variable | Description | Default |
|----------|-------------|---------|
| `GELF_LOGGER_HOST` | Graylog server hostname or IP address | `127.0.0.1` |
| `GELF_LOGGER_PORT` | GELF input port on the Graylog server | `12201` |
| `GELF_LOGGER_TRANSPORT` | Transport protocol (`udp` or `tcp`) | `udp` |
| `GELF_LOGGER_LEVEL` | Minimum log level (debug, info, notice, warning, error, critical, alert, emergency) | `warning` |
| `GELF_LOGGER_TAGS` | Comma-separated extra tags for Graylog stream filtering | _(empty)_ |

### Publishing Configuration

To customize processors or other advanced settings, publish the config file:

```bash
php artisan vendor:publish --tag=gelf-logger-config
```

This creates `config/gelf-logger.php` where you can modify the processor list and other options.

### Laravel Logging Configuration

The package automatically registers the `gelf` channel. You can add it to your logging stack in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'gelf'],
    ],
],
```

Or use it as the default log channel:

```env
LOG_CHANNEL=gelf
```

## Usage

### Basic Logging

Use the `gelf` channel to send logs to Graylog:

```php
use Illuminate\Support\Facades\Log;

Log::channel('gelf')->info('User logged in', [
    'user_id' => 123,
    'ip_address' => '192.168.1.1',
]);

Log::channel('gelf')->error('Payment failed', [
    'order_id' => 456,
    'amount' => 99.99,
]);
```

### Exception Logging

When logging exceptions, pass the exception in the context array with the key `exception`:

```php
try {
    // Your code
} catch (\Exception $e) {
    Log::channel('gelf')->error('Failed to process order: ' . $e->getMessage(), [
        'exception' => $e,
        'order_id' => 123,
        'user_id' => 456,
    ]);
}
```

The formatter will automatically extract and flatten exception data into GELF additional fields:
- `exception_class` — The exception class name
- `exception_message` — The exception message
- `exception_code` — The exception code
- `exception_file` — File and line where the exception occurred
- `exception_trace` — Full stack trace (when available)

### Log Context & the `extras` Field

All custom context data you pass to a log call is grouped into a single **`extras`** JSON field in Graylog (rather than scattered as individual top-level fields). This keeps Graylog fields clean and makes Grafana dashboard building easier across multiple apps.

```php
Log::channel('gelf')->warning('High memory usage', [
    'memory_used' => '512MB',
    'memory_limit' => '256MB',
    'server' => 'web-01',
]);
```

In Graylog, this appears as:

```json
{
  "extras": "{\"memory_used\":\"512MB\",\"memory_limit\":\"256MB\",\"server\":\"web-01\"}"
}
```

Exception data (`exception_*` fields) is **not** included in `extras` — it stays as individual top-level fields for easy searching.

### Laravel Context Integration

Data added via Laravel's `Context` facade is automatically included in the `extras` field alongside your custom context. This is useful for request tracing across logs.

```php
// In AppServiceProvider::boot() or middleware
use Illuminate\Support\Facades\Context;

Context::add('request_id', uniqid());
```

Now every log entry in that request will include the data in `extras`:

```json
{
  "extras": "{\"user\":{...},\"request_id\":\"6614a3b2e4f01\"}"
}
```

This allows you to correlate all log entries from a single request by searching for the value in Graylog.

## Tags

Tags are used by Graylog for **stream routing** — stream rules can require specific tags to be present for a log to be routed to a particular stream.

The `glfapp` tag is **always included** automatically. This tag is required by Graylog stream rules to identify logs coming from Laravel applications using this package.

You can add extra per-app tags via the `GELF_LOGGER_TAGS` environment variable:

```env
# Single tag
GELF_LOGGER_TAGS=siakad-btp

# Multiple tags (comma-separated)
GELF_LOGGER_TAGS=siakad-btp,academic
```

This is useful when multiple applications send logs to the same Graylog server — you can filter by app within a stream.

## Processors

The package includes several Monolog processors by default:

| Processor | Description |
|-----------|-------------|
| `GitProcessor` | Adds current Git branch and commit hash |
| `MemoryUsageProcessor` | Adds current memory usage |
| `MemoryPeakUsageProcessor` | Adds peak memory usage |
| `LoadAverageProcessor` | Adds system load average |
| `WebProcessor` | Adds HTTP request data (URL, method, IP, referrer) |
| `TagProcessor` | Adds tags (`glfapp` + your custom tags) |

### Customizing Processors

After publishing the config file, you can modify the processor list:

```php
// config/gelf-logger.php
'processors' => [
    \Monolog\Processor\MemoryUsageProcessor::class,
    \Monolog\Processor\WebProcessor::class,
],
```

> **Note:** You don't need to add `TagProcessor` to the processors list — it is always included automatically with `glfapp` and any tags from `GELF_LOGGER_TAGS`.

## GELF Message Structure

Each log message sent to Graylog includes:

### Core Fields

| Field | Description |
|-------|-------------|
| `short_message` | The log message |
| `host` | System name (from Monolog) |
| `level` | Syslog priority level (0-7) |
| `timestamp` | Log timestamp |
| `facility` | The log channel name |
| `service` | Application name (from `config('app.name')`) |
| `hostname` | Server hostname (from `gethostname()`) |
| `log_status` | Human-readable log level name |

### Processor Fields (individual top-level fields)

| Field | Source |
|-------|--------|
| `tags` | `TagProcessor` — always includes `glfapp` |
| `git_branch`, `git_commit` | `GitProcessor` |
| `memory_usage`, `memory_peak_usage` | `MemoryUsageProcessor`, `MemoryPeakUsageProcessor` |
| `load_average` | `LoadAverageProcessor` |
| `url`, `ip`, `http_method`, `server`, `referrer` | `WebProcessor` |

### Exception Fields (individual top-level fields)

| Field | Description |
|-------|-------------|
| `exception_class` | Exception class name |
| `exception_message` | Exception message |
| `exception_code` | Exception code |
| `exception_file` | File and line where the exception occurred |
| `exception_trace` | Full stack trace |

### Extras Field (single JSON field)

| Field | Description |
|-------|-------------|
| `extras` | JSON string containing all user-passed context data and Laravel `Context::add()` data |

The `extras` field groups all custom data into one place, keeping Graylog fields clean and Grafana dashboards consistent across multiple applications.

## Log Level Mapping

Monolog levels are mapped to GELF/syslog priorities:

| Monolog Level | GELF Priority | Syslog Equivalent |
|---------------|---------------|-------------------|
| Emergency | 0 | Emergency |
| Alert | 1 | Alert |
| Critical | 2 | Critical |
| Error | 3 | Error |
| Warning | 4 | Warning |
| Notice | 5 | Notice |
| Info | 6 | Informational |
| Debug | 7 | Debug |

## Graylog Setup

To receive logs from this package, your Graylog server needs a **GELF input** configured:

1. Go to **System → Inputs** in the Graylog web interface
2. Select **GELF UDP** (or **GELF TCP** if using TCP transport) from the dropdown
3. Click **Launch new input**
4. Set the port to `12201` (or your configured port)
5. Save and start the input

Ensure the port is accessible from your Laravel application server.

## Testing

Test your Graylog integration using the included Artisan command:

```bash
php artisan gelf:send-test-exception
```

This command sends a test exception to the configured GELF server and confirms success.

## Development

### Code Style

Format code with Laravel Pint:

```bash
vendor/bin/pint
```

### Static Analysis

Run PHPStan for static analysis:

```bash
vendor/bin/phpstan analyze
```

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Credits

- [Shadowbane](https://github.com/shadowbane)

## Support

If you discover any issues, please open an issue on the [GitHub repository](https://github.com/shadowbane/laravel-gelf-logger/issues).
