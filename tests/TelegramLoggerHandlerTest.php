<?php

namespace AshishGusai\TelegramLogger\Tests;

use AshishGusai\TelegramLogger\Jobs\SendTelegramLogJob;
use AshishGusai\TelegramLogger\Logging\TelegramLoggerHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;

class TelegramLoggerHandlerTest extends TestCase
{
    private function makeRecord(
        Level $level = Level::Critical,
        string $message = 'Database connection lost',
        array $context = []
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-05-28 12:34:56'),
            channel: 'telegram',
            level: $level,
            message: $message,
            context: $context,
        );
    }

    public function test_it_sends_synchronous_http_request_with_html_payload(): void
    {
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(message: 'Disk full'));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $data['chat_id'] === '-1001234567890'
                && $data['parse_mode'] === 'HTML'
                && str_contains($data['text'], '[CRITICAL]')
                && str_contains($data['text'], 'Disk full');
        });
    }

    public function test_it_does_not_send_when_disabled(): void
    {
        config()->set('telegram-logger.enabled', false);
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord());

        Http::assertNothingSent();
    }

    public function test_it_does_not_send_when_credentials_missing(): void
    {
        config()->set('telegram-logger.token', null);
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord());

        Http::assertNothingSent();
    }

    public function test_it_escapes_html_special_characters_in_message(): void
    {
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(message: '<script>alert("x")</script>'));

        Http::assertSent(function ($request) {
            $text = $request->data()['text'];
            return str_contains($text, '&lt;script&gt;')
                && ! str_contains($text, '<script>alert(');
        });
    }

    public function test_it_formats_exception_context_with_trace(): void
    {
        Http::fake();

        $exception = new RuntimeException('Something exploded');

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(
            message: 'Uncaught exception',
            context: ['exception' => $exception]
        ));

        Http::assertSent(function ($request) use ($exception) {
            $text = $request->data()['text'];
            return str_contains($text, 'RuntimeException')
                && str_contains($text, (string) $exception->getLine())
                && str_contains($text, '📜')
                && str_contains($text, 'Something exploded');
        });
    }

    public function test_it_formats_array_context_as_json(): void
    {
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(
            message: 'Resource warning',
            context: ['disk_space_left' => '2%', 'subsystem' => 'uploads']
        ));

        Http::assertSent(function ($request) {
            $text = $request->data()['text'];
            return str_contains($text, '📦')
                && str_contains($text, 'disk_space_left')
                && str_contains($text, '2%')
                && str_contains($text, 'uploads');
        });
    }

    public function test_it_truncates_payloads_that_exceed_safe_budget(): void
    {
        Http::fake();

        $bigBlob = str_repeat('A', 8000);

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(
            message: 'Verbose failure',
            context: ['blob' => $bigBlob]
        ));

        Http::assertSent(function ($request) {
            $text = $request->data()['text'];
            return mb_strlen($text, 'UTF-8') <= 4096
                && str_contains($text, '[Truncated due to Telegram length limits]');
        });
    }

    public function test_it_suppresses_duplicate_alerts_within_rate_limit_window(): void
    {
        config()->set('telegram-logger.rate_limit.enabled', true);
        config()->set('telegram-logger.rate_limit.window', 300);
        Cache::flush();
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(message: 'Same error'));
        $handler->handle($this->makeRecord(message: 'Same error'));
        $handler->handle($this->makeRecord(message: 'Same error'));

        Http::assertSentCount(1);
    }

    public function test_distinct_messages_are_not_rate_limited(): void
    {
        config()->set('telegram-logger.rate_limit.enabled', true);
        config()->set('telegram-logger.rate_limit.window', 300);
        Cache::flush();
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(message: 'Error A'));
        $handler->handle($this->makeRecord(message: 'Error B'));
        $handler->handle($this->makeRecord(message: 'Error C'));

        Http::assertSentCount(3);
    }

    public function test_it_routes_to_level_specific_thread_id_when_configured(): void
    {
        config()->set('telegram-logger.thread_ids', [
            'default'  => '10',
            'critical' => '42',
        ]);
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord());

        Http::assertSent(function ($request) {
            return ($request->data()['message_thread_id'] ?? null) === '42';
        });
    }

    public function test_it_falls_back_to_default_thread_id_for_unmapped_levels(): void
    {
        config()->set('telegram-logger.thread_ids', [
            'default'  => '10',
            'critical' => '42',
        ]);
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Error);
        $handler->handle($this->makeRecord(level: Level::Error));

        Http::assertSent(function ($request) {
            return ($request->data()['message_thread_id'] ?? null) === '10';
        });
    }

    public function test_it_omits_thread_id_field_when_none_configured(): void
    {
        config()->set('telegram-logger.thread_ids', []);
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord());

        Http::assertSent(function ($request) {
            return ! array_key_exists('message_thread_id', $request->data());
        });
    }

    public function test_it_dispatches_a_queued_job_when_queue_is_enabled(): void
    {
        config()->set('telegram-logger.queue.enabled', true);
        config()->set('telegram-logger.queue.connection', 'redis');
        config()->set('telegram-logger.queue.queue', 'alerts');
        Bus::fake();
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(message: 'Queued failure'));

        Bus::assertDispatched(SendTelegramLogJob::class, function ($job) {
            return $job->connection === 'redis' && $job->queue === 'alerts';
        });
        Http::assertNothingSent();
    }

    public function test_level_threshold_drops_below_configured_severity(): void
    {
        Http::fake();

        $handler = new TelegramLoggerHandler(Level::Critical);
        $handler->handle($this->makeRecord(level: Level::Warning, message: 'low'));

        Http::assertNothingSent();
    }
}
