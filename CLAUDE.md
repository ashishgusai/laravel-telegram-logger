# laravel-telegram-logger

A Laravel package (Monolog driver) that routes application logs to a Telegram bot. Hooks natively into Laravel's `Log` facade — no exception-handler hacks.

## Commands

```bash
composer install          # install dependencies
composer test             # run the full PHPUnit suite
vendor/bin/phpunit        # same, directly
```

## Project structure

```
config/
  telegram-logger.php           # publishable config (token, chat_id, thread_ids, queue, rate_limit)
src/
  TelegramLoggerServiceProvider.php   # registers + publishes config
  Logging/
    TelegramLoggerHandler.php         # Monolog AbstractProcessingHandler (core logic)
    TelegramLoggingInstance.php       # channel factory for Laravel's 'driver' => 'custom'
  Jobs/
    SendTelegramLogJob.php            # ShouldQueue job for async dispatch
tests/
  TestCase.php                        # Orchestra Testbench base, binds config defaults
  TelegramLoggerHandlerTest.php       # handler: formatting, escaping, rate-limit, thread routing, queue, truncation
  SendTelegramLogJobTest.php          # job: http payload, thread_id, missing credentials
.github/workflows/
  tests.yml                           # matrix: PHP 8.2-8.4 × Laravel 10-12
```

## Architecture

- `TelegramLoggerHandler::write()` is the entry point for every log record Monolog passes through.
- **Rate limiting**: SHA-hashes `level|message`, caches with configurable TTL. Identical messages within the window are dropped.
- **Topic routing**: resolves `thread_ids[level]` then `thread_ids[default]`; omits `message_thread_id` if neither is set.
- **Truncation**: safe budget is 4 000 chars (Telegram hard cap is 4 096). Anything over is cut and marked `[Truncated...]`.
- **Dispatch**: if `queue.enabled` is true, pushes `SendTelegramLogJob`; otherwise fires a synchronous `Http::post()`.
- **HTML escaping**: all user-supplied strings (message, context, exception message, file paths) go through `htmlspecialchars()` before insertion into `<code>`/`<pre>` tags.

## Namespace

`AshishGusai\TelegramLogger\`  — maps to `src/`.
`AshishGusai\TelegramLogger\Tests\` — maps to `tests/`.

## Config keys (telegram-logger.php)

| Key | Env var | Default |
|-----|---------|---------|
| `token` | `TELEGRAM_LOG_BOT_TOKEN` | — |
| `chat_id` | `TELEGRAM_LOG_CHAT_ID` | — |
| `enabled` | `TELEGRAM_LOG_ENABLED` | `true` |
| `thread_ids.default` | `TELEGRAM_LOG_DEFAULT_THREAD_ID` | `null` |
| `thread_ids.critical` | `TELEGRAM_LOG_CRITICAL_THREAD_ID` | `null` |
| `queue.enabled` | `TELEGRAM_LOG_QUEUE_ENABLED` | `false` |
| `queue.connection` | `TELEGRAM_LOG_QUEUE_CONNECTION` | `null` |
| `queue.queue` | `TELEGRAM_LOG_QUEUE_NAME` | `default` |
| `rate_limit.enabled` | `TELEGRAM_LOG_RATE_LIMIT_ENABLED` | `true` |
| `rate_limit.window` | `TELEGRAM_LOG_RATE_LIMIT_WINDOW` | `300` |
| `rate_limit.store` | `TELEGRAM_LOG_RATE_LIMIT_STORE` | `null` (app default) |
| `http.endpoint` | `TELEGRAM_LOG_API_ENDPOINT` | `https://api.telegram.org` |
| `http.timeout` | `TELEGRAM_LOG_HTTP_TIMEOUT` | `5` |

## Testing conventions

- All tests extend `AshishGusai\TelegramLogger\Tests\TestCase` (Orchestra Testbench).
- `Http::fake()` intercepts all outbound HTTP — never hits the real Telegram API.
- `Bus::fake()` intercepts queue dispatch in the queued-job test.
- `Cache::flush()` is called before each rate-limit test to prevent cross-test bleed.
- `config()->set(...)` is used inside test methods to override defaults without touching `TestCase::defineEnvironment()`.
- Rate limiting is **disabled** by default in `TestCase` (`rate_limit.enabled = false`) to avoid cross-test interference; individual tests enable it and flush the cache themselves.

## Adding a new feature

1. Add config key to `config/telegram-logger.php` with an `env()` fallback.
2. Wire the key into `TelegramLoggerHandler` (or `SendTelegramLogJob` for queue-side behaviour).
3. Add a test in `TelegramLoggerHandlerTest` or `SendTelegramLogJobTest` that uses `Http::fake()` / `Bus::fake()` to assert the new behaviour.
4. Run `composer test` before committing.

## CI matrix

`tests.yml` covers:

| PHP | Laravel 10 | Laravel 11 | Laravel 12 |
|-----|:---:|:---:|:---:|
| 8.2 | ✅ | ✅ | ✅ |
| 8.3 | ✅ | ✅ | ✅ |
| 8.4 | — | ✅ | ✅ |

PHP 8.4 + Laravel 10 is excluded — Laravel 10 does not officially support PHP 8.4.

## Release checklist

1. Merge feature branch into `main`.
2. `git tag -a vX.Y.Z -m "..."` + `git push origin vX.Y.Z`.
3. Packagist auto-syncs via the GitHub App webhook (installed separately).
