# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [2.0.0] - 2026-03-14

### Added
- Comprehensive PHPDoc for all classes and methods
- `extras` JSON field — groups all user-passed context and Laravel `Context::add()` data into a single field
- Configurable tags via `GELF_LOGGER_TAGS` environment variable (comma-separated)
- Config publishing support (`php artisan vendor:publish --tag=gelf-logger-config`)
- Config validation for required keys (`host`, `port`, `transport`)
- `TestException` extracted to its own file (`src/Exceptions/TestException.php`)
- Comprehensive README with full documentation
- `autoload-dev` section in `composer.json` for tests namespace
- `authors` field in `composer.json`

### Changed
- **Breaking:** Minimum PHP version bumped from `^8.1` to `^8.2`
- **Breaking:** `extras` field replaces individual context fields in GELF messages — user context and Laravel Context data are now grouped into a single `extras` JSON field instead of separate top-level fields
- `SendException` command now logs via `Log::channel('gelf')` instead of throwing an uncaught exception
- `GelfHandler` uses constructor promotion for `$publisher` property
- `GelfLogger::getLevel()` simplified to use `Level::fromName()` instead of exhaustive match
- `GelfLogger::getProcessors()` simplified — removes user-defined TagProcessor and rebuilds with merged tags
- `GelfLogger::getTransport()` normalizes to lowercase
- Updated `orchestra/testbench` requirement to `^9.0 || ^10.0` (Laravel 11/12 support)
- Cleaned up keywords in `composer.json`

### Fixed
- PHP 8.4 deprecation: explicit nullable type for `TestException::__construct()` parameter `$previous`
- Removed unused `$tagProcessor` variable in `getProcessors()`
- Removed unnecessary `isset()` checks on `LogRecord` properties in formatter
- Removed commented `ray()` debug call

### Removed
- `laravel_context` field — redundant since Laravel already injects `Context::add()` data into log records, now captured in `extras`


## [1.0.0] - 2023-07-04

### Added
- Initial release.


[2.0.0]: https://github.com/shadowbane/laravel-gelf-logger/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/shadowbane/laravel-gelf-logger/releases/tag/v1.0.0
