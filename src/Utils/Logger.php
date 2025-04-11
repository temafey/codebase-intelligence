<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Utils;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

/**
 * Logger class for the application
 */
class Logger implements LoggerInterface
{
    private MonologLogger $logger;
    private array $levelMap = [
        'debug' => LogLevel::DEBUG,
        'info' => LogLevel::INFO,
        'notice' => LogLevel::NOTICE,
        'warning' => LogLevel::WARNING,
        'error' => LogLevel::ERROR,
        'critical' => LogLevel::CRITICAL,
        'alert' => LogLevel::ALERT,
        'emergency' => LogLevel::EMERGENCY,
    ];

    /**
     * Constructor
     */
    public function __construct(string $name, string $level = 'info', ?string $logDir = null)
    {
        // Create Monolog logger
        $this->logger = new MonologLogger($name);

        // Add console handler
        $consoleHandler = new StreamHandler(STDOUT, $this->translateLevel($level));
        $consoleFormatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $consoleHandler->setFormatter($consoleFormatter);
        $this->logger->pushHandler($consoleHandler);

        // Add file handler if log directory is provided
        if ($logDir !== null) {
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $fileHandler = new RotatingFileHandler(
                $logDir . '/' . $name . '.log',
                10, // Keep 10 days of logs
                $this->translateLevel($level)
            );

            $fileFormatter = new LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                'Y-m-d H:i:s',
                true,
                true
            );
            $fileHandler->setFormatter($fileFormatter);
            $this->logger->pushHandler($fileHandler);
        }
    }

    /**
     * Translate string level to Monolog level
     */
    private function translateLevel(string $level): int
    {
        $level = strtolower($level);

        return match ($level) {
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'notice' => MonologLogger::NOTICE,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
            'critical' => MonologLogger::CRITICAL,
            'alert' => MonologLogger::ALERT,
            'emergency' => MonologLogger::EMERGENCY,
            default => MonologLogger::INFO
        };
    }

    /**
     * System is unusable.
     */
    public function emergency($message, array $context = []): void
    {
        $this->logger->emergency($message, $context);
    }

    /**
     * Action must be taken immediately.
     */
    public function alert($message, array $context = []): void
    {
        $this->logger->alert($message, $context);
    }

    /**
     * Critical conditions.
     */
    public function critical($message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should be logged.
     */
    public function error($message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     */
    public function warning($message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * Normal but significant events.
     */
    public function notice($message, array $context = []): void
    {
        $this->logger->notice($message, $context);
    }

    /**
     * Interesting events.
     */
    public function info($message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * Detailed debug information.
     */
    public function debug($message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    /**
     * Logs with an arbitrary level.
     */
    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
