<?php

namespace AshishGusai\TelegramLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendTelegramLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        protected string $text,
        protected ?string $threadId = null
    ) {
    }

    public function handle(): void
    {
        $token  = config('telegram-logger.token');
        $chatId = config('telegram-logger.chat_id');

        if (empty($token) || empty($chatId)) {
            return;
        }

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $this->text,
            'parse_mode' => 'HTML',
        ];

        if ($this->threadId !== null && $this->threadId !== '') {
            $payload['message_thread_id'] = $this->threadId;
        }

        $endpoint = rtrim(config('telegram-logger.http.endpoint', 'https://api.telegram.org'), '/');
        $timeout  = (int) config('telegram-logger.http.timeout', 5);

        Http::timeout($timeout)
            ->post("{$endpoint}/bot{$token}/sendMessage", $payload);
    }
}
