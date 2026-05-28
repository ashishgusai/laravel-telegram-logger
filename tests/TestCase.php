<?php

namespace AshishGusai\TelegramLogger\Tests;

use AshishGusai\TelegramLogger\TelegramLoggerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TelegramLoggerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('telegram-logger.token', 'test-token');
        $app['config']->set('telegram-logger.chat_id', '-1001234567890');
        $app['config']->set('telegram-logger.enabled', true);
        $app['config']->set('telegram-logger.rate_limit.enabled', false);
        $app['config']->set('telegram-logger.queue.enabled', false);
        $app['config']->set('telegram-logger.http.endpoint', 'https://api.telegram.org');
        $app['config']->set('telegram-logger.http.timeout', 5);
        $app['config']->set('telegram-logger.thread_ids', []);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }
}
