<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram Bot Credentials
    |--------------------------------------------------------------------------
    |
    | The bot token obtained from @BotFather and the chat (or channel) ID
    | the bot should post error logs to. The chat ID may be a user ID, a
    | group ID (negative integer) or a supergroup/channel ID prefixed with
    | "-100".
    |
    */
    'token'   => env('TELEGRAM_LOG_BOT_TOKEN'),
    'chat_id' => env('TELEGRAM_LOG_CHAT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Topic / Thread Routing
    |--------------------------------------------------------------------------
    |
    | Telegram supergroups support topics. Provide a message_thread_id per
    | log level to route different severities to different topic threads.
    | Keys must be lowercase Monolog level names: emergency, alert, critical,
    | error, warning, notice, info, debug, or "default" as a fallback.
    |
    */
    'thread_ids' => [
        'default'   => env('TELEGRAM_LOG_DEFAULT_THREAD_ID'),
        'critical'  => env('TELEGRAM_LOG_CRITICAL_THREAD_ID'),
        'emergency' => env('TELEGRAM_LOG_EMERGENCY_THREAD_ID'),
        'alert'     => env('TELEGRAM_LOG_ALERT_THREAD_ID'),
        'error'     => env('TELEGRAM_LOG_ERROR_THREAD_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Master Toggle
    |--------------------------------------------------------------------------
    |
    | Set TELEGRAM_LOG_ENABLED=false in local/staging .env files to prevent
    | accidental log dispatch during development.
    |
    */
    'enabled' => env('TELEGRAM_LOG_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Queue Dispatch
    |--------------------------------------------------------------------------
    |
    | When enabled, log delivery is handed off to a queued job so the user
    | request is never blocked by Telegram API latency. Requires a working
    | queue worker. When disabled, the HTTP call is fired synchronously
    | with a short timeout.
    |
    */
    'queue' => [
        'enabled'    => env('TELEGRAM_LOG_QUEUE_ENABLED', false),
        'connection' => env('TELEGRAM_LOG_QUEUE_CONNECTION'),
        'queue'      => env('TELEGRAM_LOG_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting / De-duplication
    |--------------------------------------------------------------------------
    |
    | Hash each log signature (level + message) and cache it for the given
    | window. Identical alerts emitted within the window are suppressed to
    | prevent repetition storms during cascading failures.
    |
    | global_max_per_minute caps the *total* messages dispatched in any single
    | wall-clock minute, regardless of signature diversity. Prevents "variety
    | storms" where many distinct exceptions fire at once. Set to 0 to disable.
    |
    */
    'rate_limit' => [
        'enabled'               => env('TELEGRAM_LOG_RATE_LIMIT_ENABLED', true),
        'window'                => env('TELEGRAM_LOG_RATE_LIMIT_WINDOW', 300),
        'store'                 => env('TELEGRAM_LOG_RATE_LIMIT_STORE'),
        'global_max_per_minute' => env('TELEGRAM_LOG_GLOBAL_MAX_PER_MIN', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    |
    | Tunables for the underlying Telegram Bot API HTTP request. The
    | endpoint is overridable mostly for testing against a mock server.
    |
    */
    'http' => [
        'endpoint' => env('TELEGRAM_LOG_API_ENDPOINT', 'https://api.telegram.org'),
        'timeout'  => env('TELEGRAM_LOG_HTTP_TIMEOUT', 5),
    ],
];
