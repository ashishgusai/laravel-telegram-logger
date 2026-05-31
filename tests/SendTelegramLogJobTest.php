<?php

namespace AshishGusai\TelegramLogger\Tests;

use AshishGusai\TelegramLogger\Jobs\SendTelegramLogJob;
use Illuminate\Support\Facades\Http;

class SendTelegramLogJobTest extends TestCase
{
    public function test_it_posts_payload_to_telegram_endpoint(): void
    {
        Http::fake();

        (new SendTelegramLogJob('Hello <b>world</b>'))->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && $data['chat_id'] === '-1001234567890'
                && $data['text'] === 'Hello <b>world</b>'
                && $data['parse_mode'] === 'HTML'
                && ! array_key_exists('message_thread_id', $data);
        });
    }

    public function test_it_includes_thread_id_when_provided(): void
    {
        Http::fake();

        (new SendTelegramLogJob('msg', '42'))->handle();

        Http::assertSent(function ($request) {
            return ($request->data()['message_thread_id'] ?? null) === '42';
        });
    }

    public function test_it_short_circuits_when_credentials_missing(): void
    {
        config()->set('telegram-logger.token', null);
        Http::fake();

        (new SendTelegramLogJob('msg'))->handle();

        Http::assertNothingSent();
    }
}
