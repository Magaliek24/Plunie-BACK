<?php

declare(strict_types=1);

namespace App\core;

use Monolog\\Logger as MonoLogger;
use Monolog\\Handler\\StreamHandler;
use Monolog\\Processor\\UidProcessor;
use Monolog\\Processor\\PsrLogMessageProcessor;

final class Logger
{
    private static ?MonoLogger $instance = null;

    public static function get(): MonoLogger
    {
        if (self::$instance) return self::$instance;

        $level = Env::get('LOG_LEVEL', 'debug');
        $name  = 'plunie';
        $logger = new MonoLogger($name);

        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        $file = $logDir . '/app-' . date('Y-m-d') . '.log';
        $handler = new StreamHandler($file, MonoLogger::toMonologLevel($level));

        $logger->pushProcessor(new UidProcessor());
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushHandler($handler);

        self::$instance = $logger;
        return self::$instance;
    }
}