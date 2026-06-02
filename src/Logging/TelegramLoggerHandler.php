<?php

namespace AshishGusai\TelegramLogger\Logging;

use AshishGusai\TelegramLogger\Jobs\SendTelegramLogJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Throwable;

class TelegramLoggerHandler extends AbstractProcessingHandler
{
    private const TELEGRAM_MAX_LENGTH = 4096;
    private const SAFE_BUDGET = 4000;

    protected function write(LogRecord $record): void
    {
        if (! config('telegram-logger.enabled', true)) {
            return;
        }

        if ($this->isGlobalRateLimited()) {
            return;
        }

        if ($this->isRateLimited($record)) {
            return;
        }

        $message  = $this->formatMessage($record);
        $threadId = $this->resolveThreadId($record->level->name);

        if (config('telegram-logger.queue.enabled', false)) {
            $this->dispatchQueued($message, $threadId);
            return;
        }

        $this->dispatchSync($message, $threadId);
    }

    protected function isRateLimited(LogRecord $record): bool
    {
        if (! config('telegram-logger.rate_limit.enabled', true)) {
            return false;
        }

        $window = (int) config('telegram-logger.rate_limit.window', 300);
        if ($window <= 0) {
            return false;
        }

        $store = config('telegram-logger.rate_limit.store');
        $cache = $store ? Cache::store($store) : Cache::store();

        $key = 'tg_log:' . md5($record->level->name . '|' . $record->message);

        if ($cache->has($key)) {
            return true;
        }

        $cache->put($key, 1, $window);

        return false;
    }

    protected function isGlobalRateLimited(): bool
    {
        $max = (int) config('telegram-logger.rate_limit.global_max_per_minute', 10);

        if ($max <= 0) {
            return false;
        }

        $store = config('telegram-logger.rate_limit.store');
        $cache = $store ? Cache::store($store) : Cache::store();
        $bucket = 'tg_log_global:' . (int) floor(time() / 60);

        // Cache::add writes only if key absent — atomic on Redis/Memcached.
        $cache->add($bucket, 0, 70);

        $count = (int) $cache->get($bucket, 0);

        if ($count >= $max) {
            return true;
        }

        $cache->increment($bucket);

        return false;
    }

    protected function formatMessage(LogRecord $record): string
    {
        $env   = function_exists('app') ? app()->environment() : 'unknown';
        $level = strtoupper($record->level->name);
        $emoji = $this->emojiForLevel($level);

        $header  = "{$emoji} <b>[{$level}]</b> - <code>" . $this->escape($env) . "</code>\n";
        $header .= '📅 <b>Time:</b> ' . $record->datetime->format('Y-m-d H:i:s') . "\n";
        $header .= '📝 <b>Message:</b> ' . $this->escape($record->message) . "\n\n";

        $body = $this->formatBody($record);

        if (mb_strlen($header . $body, 'UTF-8') > self::SAFE_BUDGET) {
            $body = $this->truncate($header, $body);
        }

        return $header . $body;
    }

    protected function formatBody(LogRecord $record): string
    {
        $context = $record->context;

        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            return $this->formatException($context['exception']);
        }

        if (! empty($context)) {
            $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                return '';
            }
            return "📦 <b>Context:</b>\n<pre><code class=\"language-json\">" . $this->escape($json) . "</code></pre>";
        }

        return '';
    }

    protected function formatException(Throwable $e): string
    {
        $out  = '💥 <b>Exception:</b> ' . $this->escape(get_class($e)) . "\n";
        $out .= '📁 <b>File:</b> <code>' . $this->escape($e->getFile() . ':' . $e->getLine()) . "</code>\n";

        $exceptionMessage = $e->getMessage();
        if ($exceptionMessage !== '') {
            $out .= '💬 <b>Detail:</b> ' . $this->escape($exceptionMessage) . "\n";
        }

        $out .= "📜 <b>Trace:</b>\n<pre><code class=\"language-php\">" . $this->escape($e->getTraceAsString()) . "</code></pre>";

        return $out;
    }

    protected function truncate(string $header, string $body): string
    {
        $marker     = "\n\n... [Truncated due to Telegram length limits]";
        $markerLen  = mb_strlen($marker, 'UTF-8');
        $headerLen  = mb_strlen($header, 'UTF-8');
        $allowed    = self::SAFE_BUDGET - $headerLen - $markerLen;

        if ($allowed <= 0) {
            return $marker;
        }

        return mb_substr($body, 0, $allowed, 'UTF-8') . $marker;
    }

    protected function resolveThreadId(string $levelName): ?string
    {
        $threads = config('telegram-logger.thread_ids', []);
        $key     = strtolower($levelName);

        $value = $threads[$key] ?? $threads['default'] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    protected function dispatchQueued(string $message, ?string $threadId): void
    {
        $job = SendTelegramLogJob::dispatch($message, $threadId);

        if ($connection = config('telegram-logger.queue.connection')) {
            $job->onConnection($connection);
        }

        if ($queue = config('telegram-logger.queue.queue')) {
            $job->onQueue($queue);
        }
    }

    protected function dispatchSync(string $message, ?string $threadId): void
    {
        $token  = config('telegram-logger.token');
        $chatId = config('telegram-logger.chat_id');

        if (empty($token) || empty($chatId)) {
            return;
        }

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ];

        if ($threadId !== null) {
            $payload['message_thread_id'] = $threadId;
        }

        $endpoint = rtrim(config('telegram-logger.http.endpoint', 'https://api.telegram.org'), '/');
        $timeout  = (int) config('telegram-logger.http.timeout', 5);

        Http::timeout($timeout)
            ->post("{$endpoint}/bot{$token}/sendMessage", $payload);
    }

    protected function emojiForLevel(string $level): string
    {
        return match (strtoupper($level)) {
            'EMERGENCY', 'ALERT', 'CRITICAL' => '🚨',
            'ERROR'                          => '❌',
            'WARNING'                        => '⚠️',
            'NOTICE', 'INFO'                 => 'ℹ️',
            default                          => '📝',
        };
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
