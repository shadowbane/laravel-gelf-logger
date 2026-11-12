# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.0.0] - 2026-11-12

### Added

- Support for Laravel 13.
- Logs are buffered and published together instead of one network write per log line. On php-fpm and FrankenPHP the buffer is sent after the response has reached the client.
- Buffered logs are flushed after every queued job (`JobProcessed`, `JobExceptionOccurred`, `JobFailed`) and when the application terminates, so long-running queue workers do not hold logs until they exit.
- `GELF_LOGGER_BUFFER` option (default `true`). Set it to `false` to publish each record as it is logged, as in earlier versions.
- `GELF_LOGGER_TIMEOUT` option (default `2` seconds) for the TCP connect timeout.
- `GELF_LOGGER_ERROR_CHANNEL` option: a Laravel log channel that receives delivery failures.
- `phpstan.neon`, so `vendor/bin/phpstan analyze` runs as the README describes.

### Changed

- **Breaking:** buffering is on by default. Records still in memory when a process is killed (`SIGKILL`, out-of-memory) are lost; set `GELF_LOGGER_BUFFER=false` to restore the previous behaviour.
- **Breaking:** logging no longer throws when Graylog cannot be reached. Failures are reported to `GELF_LOGGER_ERROR_CHANNEL` when set and dropped otherwise. When a buffered batch fails, the rest of that batch is dropped and the report gives the count.
- Declared the `illuminate/support` and `illuminate/console` dependencies (`^11.0` to `^13.0`) that the package already relied on.
- Requires `ext-mbstring`.
- Requires PHPStan 2 for development.

### Fixed

- With the TCP transport, a Graylog server that refused connections made every log call throw a `RuntimeException` into the application.
- With the TCP transport, an unreachable Graylog host blocked each log call for 30 seconds (gelf-php's default connect timeout) and then threw.
- A log message containing invalid UTF-8 made the log call throw a `JsonException`, on any transport. Invalid bytes are now replaced.

## [2.1.1] - 2026-11-09

### Fixed

- `foreach() argument must be of type array|object, string given` in `CustomGelfFormatter` when a non-Throwable value (e.g. `['exception' => $e->getMessage()]`) was logged under the `exception` context key. Such values are now sent as `exception_message`.

## [2.1.0] - 2026-08-27

### Changed

- `exception_trace` renders the full `getPrevious()` chain, separated by `Caused by:`. Previously only the outermost throwable's trace was captured, so wrapped exceptions arrived in Graylog with no indication of the real cause.

### Fixed

- PHP's `Error` hierarchy (`TypeError`, `ValueError`, `ArgumentCountError`) is captured alongside `Exception`; exception handling now checks for `\Throwable`.

## [2.0.0] - 2026-03-14

### Added

- `extras` JSON field, grouping all user-passed context and Laravel `Context::add()` data into a single field.
- Configurable tags via the `GELF_LOGGER_TAGS` environment variable (comma-separated).
- Config publishing (`php artisan vendor:publish --tag=gelf-logger-config`).
- Validation of the required config keys (`host`, `port`, `transport`).
- README with full documentation.

### Changed

- **Breaking:** minimum PHP version raised from `^8.1` to `^8.2`.
- **Breaking:** user context and Laravel Context data are sent in a single `extras` JSON field instead of separate top-level fields.
- `php artisan gelf:send-test-exception` logs through `Log::channel('gelf')` instead of throwing an uncaught exception.
- `GELF_LOGGER_TRANSPORT` is case-insensitive.
- Support for Laravel 11 and 12 (`orchestra/testbench` `^9.0 || ^10.0`).

### Fixed

- PHP 8.4 deprecation: explicit nullable type for the `$previous` parameter of `TestException::__construct()`.

### Removed

- `laravel_context` field, redundant because Laravel already injects `Context::add()` data into log records, which are now captured in `extras`.

## [1.0.0] - 2023-07-04

### Added

- Initial release.

[Unreleased]: https://github.com/shadowbane/laravel-gelf-logger/compare/v3.0.0...HEAD
[3.0.0]: https://github.com/shadowbane/laravel-gelf-logger/compare/v2.1.1...v3.0.0
[2.1.1]: https://github.com/shadowbane/laravel-gelf-logger/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/shadowbane/laravel-gelf-logger/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/shadowbane/laravel-gelf-logger/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/shadowbane/laravel-gelf-logger/releases/tag/v1.0.0
