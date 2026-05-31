<?php

namespace AshishGusai\TelegramLogger\Logging;

use Monolog\Logger;

class TelegramLoggingInstance
{
    public function __invoke(array $config): Logger
    {
        $level   = $config['level'] ?? 'critical';
        $bubble  = $config['bubble'] ?? true;
        $channel = $config['name'] ?? 'telegram';

        return new Logger($channel, [
            new TelegramLoggerHandler(Logger::toMonologLevel($level), (bool) $bubble),
        ]);
    }
}
