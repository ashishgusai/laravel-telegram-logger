<?php

namespace AshishGusai\TelegramLogger;

use Illuminate\Support\ServiceProvider;

class TelegramLoggerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/telegram-logger.php' => config_path('telegram-logger.php'),
            ], 'telegram-logger-config');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/telegram-logger.php',
            'telegram-logger'
        );
    }
}
