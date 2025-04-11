#!/bin/bash

# Скрипт для создания утилит

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание утилит в $TARGET_DIR/src/Utils..."

# Создаем директорию Utils, если не существует
mkdir -p "$TARGET_DIR/src/Utils"

# Создаем Logger.php
cat > "$TARGET_DIR/src/Utils/Logger.php" << 'EOT'
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
EOT

# Создаем FileSystem.php
cat > "$TARGET_DIR/src/Utils/FileSystem.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Utils;

use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Psr\Log\LoggerInterface;

/**
 * Extended filesystem utilities
 */
class FileSystem
{
    private SymfonyFilesystem $filesystem;
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->filesystem = new SymfonyFilesystem();
        $this->logger = $logger ?? new Logger('filesystem');
    }

    /**
     * Find files matching a pattern in a directory
     */
    public function findFiles(string $directory, string $pattern): array
    {
        $this->logger->debug("Finding files in directory", [
            'directory' => $directory,
            'pattern' => $pattern
        ]);

        $finder = new Finder();
        $finder->files()
               ->in($directory)
               ->name($pattern);

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        $this->logger->debug("Found files", ['count' => count($files)]);

        return $files;
    }

    /**
     * Create a temporary file with the given content
     */
    public function createTemporaryFile(string $content, string $extension = ''): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'claude_') . $extension;
        file_put_contents($tempFile, $content);

        $this->logger->debug("Created temporary file", ['file' => $tempFile]);

        return $tempFile;
    }

    /**
     * Get directory tree as a nested array
     */
    public function getDirectoryTree(string $directory, array $excludePatterns = []): array
    {
        $this->logger->debug("Getting directory tree", ['directory' => $directory]);

        $result = [];

        if (!is_dir($directory)) {
            $this->logger->error("Directory not found", ['directory' => $directory]);
            return $result;
        }

        $finder = new Finder();
        $finder->in($directory)
               ->depth('< 10'); // Limit depth to avoid excessive recursion

        // Add exclude patterns
        foreach ($excludePatterns as $pattern) {
            $finder->notPath($pattern);
        }

        // Separate directories and files
        $dirs = [];
        $files = [];

        foreach ($finder as $item) {
            $path = $item->getRelativePathname();

            if ($item->isDir()) {
                $dirs[$path] = [];
            } else {
                $files[] = $path;
            }
        }

        // Build the tree
        foreach ($files as $file) {
            $dir = dirname($file);
            $filename = basename($file);

            if ($dir === '.') {
                $result[$filename] = 'file';
            } else {
                $this->addToTree($result, $dir, $filename);
            }
        }

        $this->logger->debug("Directory tree built", [
            'directories' => count($dirs),
            'files' => count($files)
        ]);

        return $result;
    }

    /**
     * Add a file to the tree structure
     */
    private function addToTree(array &$tree, string $dir, string $file): void
    {
        $parts = explode('/', $dir);
        $current = &$tree;

        foreach ($parts as $part) {
            if ($part === '.') {
                continue;
            }

            if (!isset($current[$part])) {
                $current[$part] = [];
            }

            $current = &$current[$part];
        }

        $current[$file] = 'file';
    }
}
EOT

echo "Создание утилит завершено."