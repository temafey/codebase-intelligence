#!/bin/bash

# Скрипт для создания исполняемых файлов

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание исполняемых файлов в $TARGET_DIR/bin..."

# Создаем директорию bin, если не существует
mkdir -p "$TARGET_DIR/bin"

# Создаем основной исполняемый файл
cat > "$TARGET_DIR/bin/code-intelligence" << 'EOT'
#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use DI\ContainerBuilder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use CodebaseIntelligence\Command\InitCommand;
use CodebaseIntelligence\Command\IngestCommand;
use CodebaseIntelligence\Command\UpdateCommand;
use CodebaseIntelligence\Command\AnalyzeCommand;
use CodebaseIntelligence\Command\BudgetCommand;
use CodebaseIntelligence\Command\ShellCommand;
use CodebaseIntelligence\Command\MetricsCommand;
use CodebaseIntelligence\Command\CacheClearCommand;
use CodebaseIntelligence\Command\SyncCommand;
use CodebaseIntelligence\Utils\Logger;
use CodebaseIntelligence\API\ClaudeClient;
use CodebaseIntelligence\API\AIModelClientInterface;

// Setup input
$input = new ArgvInput();

// Check for custom .env path
$envPath = $input->getParameterOption(['--env', '-e'], dirname(__DIR__) . '/.env');
$dotenv = new Dotenv();

// Load environment variables
if (file_exists($envPath)) {
    $dotenv->load($envPath);
} elseif (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv->load(dirname(__DIR__) . '/.env');
}

// Load .env.local if it exists
if (file_exists(dirname(__DIR__) . '/.env.local')) {
    $dotenv->load(dirname(__DIR__) . '/.env.local');
}

// Prepare configuration from environment
$config = [
    'claude_api_key' => $_ENV['CLAUDE_API_KEY'] ?? '',
    'claude_api_url' => $_ENV['CLAUDE_API_URL'] ?? 'https://api.anthropic.com/v1',
    'claude_model' => $_ENV['CLAUDE_MODEL'] ?? 'claude-3-7-sonnet-20250219',
    'app_debug' => filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
    'app_log_level' => $_ENV['APP_LOG_LEVEL'] ?? 'info',
    'codebase_path' => $_ENV['CODEBASE_PATH'] ?? getcwd(),
    'codebase_language' => $_ENV['CODEBASE_LANGUAGE'] ?? 'php',
    'codebase_include_patterns' => $_ENV['CODEBASE_INCLUDE_PATTERNS'] ?? '*.php,*.js,*.html,*.css,*.md',
    'codebase_exclude_patterns' => $_ENV['CODEBASE_EXCLUDE_PATTERNS'] ?? 'vendor/*,node_modules/*,tests/*,storage/*',
    'cache_enabled' => filter_var($_ENV['CACHE_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
    'cache_ttl' => (int) ($_ENV['CACHE_TTL'] ?? 604800),
    'cache_storage' => $_ENV['CACHE_STORAGE'] ?? 'filesystem',
    'redis_host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
    'redis_port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
    'token_budget_daily' => (int) ($_ENV['TOKEN_BUDGET_DAILY'] ?? 50000),
    'session_ttl' => (int) ($_ENV['SESSION_TTL'] ?? 28800),
    'off_peak_discount' => (float) ($_ENV['OFF_PEAK_DISCOUNT'] ?? 0.7),
    'team_size' => (int) ($_ENV['TEAM_SIZE'] ?? 2),
    'daily_changes_average' => (int) ($_ENV['DAILY_CHANGES_AVERAGE'] ?? 20),
    'session_prefix' => $_ENV['SESSION_PREFIX'] ?? 'claude-session',
    'storage_dir' => $_ENV['STORAGE_DIR'] ?? getcwd() . '/.claude',
];

// Set up DI container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
    'config' => $config,
    LoggerInterface::class => function () use ($config) {
        return new Logger('codebase-intelligence', $config['app_log_level']);
    },
    AIModelClientInterface::class => function (\Psr\Container\ContainerInterface $c) {
        return $c->get(ClaudeClient::class);
    },
    ClaudeClient::class => function (\Psr\Container\ContainerInterface $c) {
        $config = $c->get('config');
        return new ClaudeClient(
            $config['claude_api_key'],
            $config['claude_api_url'],
            $config['claude_model'],
            $c->get(LoggerInterface::class)
        );
    },
]);

try {
    $container = $containerBuilder->build();

    // Create application
    $application = new Application('Codebase Intelligence for Claude 3.7 Sonnet', '1.0.0');

    // Register commands
    $application->add($container->get(InitCommand::class));
    // Uncomment as you implement each command
    // $application->add($container->get(IngestCommand::class));
    // $application->add($container->get(UpdateCommand::class));
    // $application->add($container->get(AnalyzeCommand::class));
    // $application->add($container->get(BudgetCommand::class));
    // $application->add($container->get(ShellCommand::class));
    // $application->add($container->get(MetricsCommand::class));
    // $application->add($container->get(CacheClearCommand::class));
    // $application->add($container->get(SyncCommand::class));

    // Run application
    $application->run($input);
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
EOT

# Делаем скрипт исполняемым
chmod +x "$TARGET_DIR/bin/code-intelligence"

echo "Создание исполняемых файлов завершено."