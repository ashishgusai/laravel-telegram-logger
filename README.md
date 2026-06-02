# laravel-telegram-logger

Send Laravel error logs to a Telegram chat, group, or supergroup topic via a Telegram Bot. Built as a Monolog driver so it plugs into the standard Laravel logging stack — no custom exception-handler hacks required.

## Features

- Native Monolog handler — works with the `Log` facade and `config/logging.php` channels.
- **Rate limiting / de-duplication** to prevent alert storms (cache-backed signature hash).
- **Topic / thread routing** for Telegram supergroups (`message_thread_id` per log level).
- **4096-character safe truncation** so verbose stack traces never get rejected by Telegram.
- **Optional queue dispatch** so the request lifecycle is never blocked by Telegram API latency.
- HTML-escaped output by default — safe with `parse_mode=HTML`.

## Requirements

- PHP `^8.2`
- Laravel `^10.0 | ^11.0 | ^12.0`
- Monolog `^3.0`

## Installation

```bash
composer require ashishgusai/laravel-telegram-logger
```

Publish the config (optional, but recommended):

```bash
php artisan vendor:publish --tag=telegram-logger-config
```

## Configuration

Add the following to your `.env`:

```dotenv
TELEGRAM_LOG_BOT_TOKEN="123456789:ABCdefGhIJKlmNoPQRsTUVwXyz"
TELEGRAM_LOG_CHAT_ID="-1001234567890"
TELEGRAM_LOG_ENABLED=true

# Optional — supergroup topic routing
TELEGRAM_LOG_DEFAULT_THREAD_ID=
TELEGRAM_LOG_CRITICAL_THREAD_ID=

# Optional — queue dispatch (recommended for production)
TELEGRAM_LOG_QUEUE_ENABLED=true
TELEGRAM_LOG_QUEUE_CONNECTION=redis
TELEGRAM_LOG_QUEUE_NAME=alerts

# Optional — rate limit duplicate alerts (seconds)
TELEGRAM_LOG_RATE_LIMIT_ENABLED=true
TELEGRAM_LOG_RATE_LIMIT_WINDOW=300

# Optional — global volume cap per minute across all signatures (0 = disabled)
TELEGRAM_LOG_GLOBAL_MAX_PER_MIN=10
```

Register the channel in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver'   => 'stack',
        'channels' => ['daily', 'telegram'],
        'ignore_exceptions' => false,
    ],

    'telegram' => [
        'driver' => 'custom',
        'via'    => \AshishGusai\TelegramLogger\Logging\TelegramLoggingInstance::class,
        'level'  => 'critical',
    ],
],
```

## Usage

```php
use Illuminate\Support\Facades\Log;

Log::critical('Database connection lost', [
    'host'   => 'db-primary',
    'reason' => 'timeout',
]);

// Exceptions are formatted with file/line and the trace
try {
    // ...
} catch (\Throwable $e) {
    Log::critical('Background job failed', ['exception' => $e]);
}
```

## How rate limiting works

Each log line is hashed by `level + message`. If the same hash is seen again within `rate_limit.window` seconds (default 300), the duplicate is silently dropped. Cache backend defaults to your app default; override with `TELEGRAM_LOG_RATE_LIMIT_STORE`.

A second gate — the **global volume cap** (`TELEGRAM_LOG_GLOBAL_MAX_PER_MIN`, default 10) — limits the absolute number of messages sent in any single wall-clock minute, regardless of how many distinct signatures are active. This prevents "variety storms" where a broken deployment throws 20 different exceptions at once and floods the channel. Set to `0` to disable the global cap.

## How thread routing works

If your Telegram chat is a supergroup with topics, set the per-level `message_thread_id`s in the config. The handler looks up `thread_ids[level_name]` first, then `thread_ids['default']`, and omits the field if both are empty.

## Queueing

Set `TELEGRAM_LOG_QUEUE_ENABLED=true` and the handler dispatches `SendTelegramLogJob` onto your queue instead of making a blocking HTTP call. The job is `tries=3` with a 10-second backoff.

## Testing

```bash
composer install
composer test
```

## License

MIT
