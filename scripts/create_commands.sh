#!/bin/bash

# Скрипт для создания командных скриптов

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание командных скриптов в $TARGET_DIR/src/Command..."

# Создаем директорию Command, если не существует
mkdir -p "$TARGET_DIR/src/Command"

# Создаем InitCommand.php
cat > "$TARGET_DIR/src/Command/InitCommand.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;

/**
 * Initializes the codebase integration with Claude
 */
class InitCommand extends Command
{
    protected static $defaultName = 'init';
    protected static $defaultDescription = 'Initialize Claude codebase integration for a project';

    private LoggerInterface $logger;
    private array $config;
    private Filesystem $filesystem;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        parent::__construct();
        $this->config = $config;
        $this->logger = $logger ?? new Logger('init-command');
        $this->filesystem = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument(
                'codebase-path',
                InputArgument::OPTIONAL,
                'Path to the codebase you want to analyze',
                getcwd()
            )
            ->addOption(
                'storage-dir',
                's',
                InputOption::VALUE_OPTIONAL,
                'Directory to store Claude session data and analysis results',
                getcwd() . '/.claude'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Primary language of the codebase (php, python, golang)',
                'php'
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_OPTIONAL,
                'File patterns to include (comma-separated)',
                '*.php,*.js,*.html,*.css,*.md'
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_OPTIONAL,
                'File patterns to exclude (comma-separated)',
                'vendor/*,node_modules/*,tests/*,storage/*'
            )
            ->addOption(
                'team-size',
                't',
                InputOption::VALUE_OPTIONAL,
                'Number of developers in the team',
                '2'
            )
            ->addOption(
                'api-key',
                'k',
                InputOption::VALUE_OPTIONAL,
                'Claude API key (recommended to use .env file instead)',
                null
            )
            ->addOption(
                'cache-type',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Cache type (filesystem or redis)',
                'filesystem'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Initializing Claude Codebase Intelligence');

        $codebasePath = $input->getArgument('codebase-path');
        $storageDir = $input->getOption('storage-dir');
        $language = $input->getOption('language');
        $include = $input->getOption('include');
        $exclude = $input->getOption('exclude');
        $teamSize = (int) $input->getOption('team-size');
        $apiKey = $input->getOption('api-key');
        $cacheType = $input->getOption('cache-type');

        // Validate codebase path
        if (!is_dir($codebasePath)) {
            $io->error("Codebase path does not exist: $codebasePath");
            return Command::FAILURE;
        }

        $this->logger->info('Initializing codebase integration', [
            'codebase_path' => $codebasePath,
            'storage_dir' => $storageDir,
            'language' => $language,
            'cache_type' => $cacheType
        ]);

        // Create storage directory
        if (!is_dir($storageDir)) {
            $io->note("Creating storage directory: $storageDir");
            $this->filesystem->mkdir($storageDir, 0755);
        }

        // Create project configuration
        $projectConfig = [
            'codebase_path' => $codebasePath,
            'codebase_language' => $language,
            'codebase_include_patterns' => $include,
            'codebase_exclude_patterns' => $exclude,
            'team_size' => $teamSize,
            'storage_dir' => $storageDir,
            'cache_storage' => $cacheType,
        ];

        // Add API key if provided
        if ($apiKey) {
            $projectConfig['claude_api_key'] = $apiKey;
        }

        // Create project-specific .env file
        $this->createProjectEnv($storageDir, $projectConfig);

        // Create directory structure inside storage
        $this->createStorageStructure($storageDir);

        // Create project overview file
        $this->createProjectOverview($codebasePath, $storageDir, $language);

        $io->success([
            'Claude Codebase Intelligence initialized successfully!',
            "Configuration saved in: $storageDir/.env",
            '',
            'Next steps:',
            '1. Review and update your .env file with your Claude API key',
            '2. Run "code-intelligence ingest" to analyze your codebase',
            '3. Run "code-intelligence budget" to estimate costs'
        ]);

        $this->logger->info('Initialization completed successfully');

        return Command::SUCCESS;
    }

    /**
     * Creates project-specific .env file
     */
    private function createProjectEnv(string $storageDir, array $projectConfig): void
    {
        $envFile = $storageDir . '/.env';

        $apiKey = $projectConfig['claude_api_key'] ?? 'your_api_key_here';
        $codebasePath = $projectConfig['codebase_path'];
        $language = $projectConfig['codebase_language'];
        $include = $projectConfig['codebase_include_patterns'];
        $exclude = $projectConfig['codebase_exclude_patterns'];
        $teamSize = $projectConfig['team_size'];
        $cacheStorage = $projectConfig['cache_storage'] ?? 'filesystem';

        $projectName = basename($codebasePath);

        $envContent = <<<EOT
# API Configuration
CLAUDE_API_KEY=$apiKey
CLAUDE_API_URL=https://api.anthropic.com/v1
CLAUDE_MODEL=claude-3-7-sonnet-20250219

# Application Settings
APP_DEBUG=false
APP_LOG_LEVEL=info
APP_TIMEZONE=UTC

# Codebase Settings
CODEBASE_PATH=$codebasePath
CODEBASE_LANGUAGE=$language
CODEBASE_INCLUDE_PATTERNS=$include
CODEBASE_EXCLUDE_PATTERNS=$exclude

# Caching
CACHE_ENABLED=true
CACHE_TTL=604800 # 1 week in seconds
CACHE_STORAGE=$cacheStorage
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Cost Optimization
TOKEN_BUDGET_DAILY=50000
SESSION_TTL=28800 # 8 hours in seconds
OFF_PEAK_DISCOUNT=0.7 # 30% discount

# Team Settings
TEAM_SIZE=$teamSize
DAILY_CHANGES_AVERAGE=20 # Average number of file changes per day

# Session Management
SESSION_PREFIX=$projectName
EOT;

        file_put_contents($envFile, $envContent);
        $this->logger->info('Created project .env file', ['path' => $envFile]);
    }

    /**
     * Creates the storage directory structure
     */
    private function createStorageStructure(string $storageDir): void
    {
        // Create subdirectories
        $dirs = [
            '/chunks',    // For semantic code chunks
            '/diffs',     // For code diffs
            '/cache',     // For response caching
            '/sessions',  // For session management
            '/logs',      // For logs
            '/docs',      // For generated documentation
            '/metrics',   // For usage metrics
            '/analysis',  // For analysis results
        ];

        foreach ($dirs as $dir) {
            $path = $storageDir . $dir;
            if (!is_dir($path)) {
                $this->filesystem->mkdir($path, 0755);
            }
        }

        $this->logger->info('Created storage directory structure');
    }

    /**
     * Creates a basic project overview file
     */
    private function createProjectOverview(string $codebasePath, string $storageDir, string $language): void
    {
        $projectName = basename($codebasePath);
        $docsDir = $storageDir . '/docs';
        $overviewFile = $docsDir . '/project_overview.md';

        // Get basic codebase structure
        $structure = $this->getCodebaseStructure($codebasePath, $language);

        $content = <<<EOT
# Project Overview: $projectName

## Structure
```
$structure
```

## Purpose
This project is managed using Claude 3.7 Sonnet for codebase intelligence.

## Dependencies
*Auto-detected dependencies will be added here during ingestion*

## Key Components
*Key components will be identified during ingestion*

## Main Workflows
*Main workflows will be analyzed during ingestion*

## Notes
*Additional notes about the project architecture and patterns*
EOT;

        file_put_contents($overviewFile, $content);
        $this->logger->info('Created project overview file', ['path' => $overviewFile]);
    }

    /**
     * Gets a simplified codebase structure
     */
    private function getCodebaseStructure(string $codebasePath, string $language): string
    {
        $extensions = match ($language) {
            'php' => ['php'],
            'python' => ['py'],
            'golang' => ['go'],
            default => ['php', 'js', 'html', 'css']
        };

        $extPattern = implode('|', array_map(fn($ext) => "\.$ext", $extensions));

        $cmd = "find \"$codebasePath\" -type f -regex \".*($extPattern)\" | sort | head -n 100";
        exec($cmd, $files, $exitCode);

        if ($exitCode !== 0 || empty($files)) {
            return "Unable to generate structure or no matching files found.";
        }

        // Create a tree-like representation
        $basePath = rtrim($codebasePath, '/') . '/';
        $structure = [];

        foreach ($files as $file) {
            // Get relative path
            $relativePath = str_replace($basePath, '', $file);
            $parts = explode('/', $relativePath);

            // Build structure
            $current = &$structure;
            $path = '';

            foreach ($parts as $i => $part) {
                $path .= ($i > 0 ? '/' : '') . $part;

                if ($i === count($parts) - 1) {
                    // This is a file
                    $current[] = $part;
                } else {
                    // This is a directory
                    if (!isset($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
        }

        // Convert structure to string representation
        return $this->formatStructure($structure);
    }

    /**
     * Formats structure array into a string
     */
    private function formatStructure(array $structure, string $prefix = ''): string
    {
        $result = '';
        $lastKey = array_key_last(is_array($structure) ? $structure : []);

        foreach ($structure as $key => $value) {
            $isLast = $key === $lastKey;
            $connector = $isLast ? '└── ' : '├── ';
            $childPrefix = $isLast ? '    ' : '│   ';

            if (is_int($key)) {
                // This is a file
                $result .= "$prefix$connector$value\n";
            } else {
                // This is a directory
                $result .= "$prefix$connector$key/\n";
                $result .= $this->formatStructure($value, $prefix . $childPrefix);
            }
        }

        return $result;
    }
}
EOT

# Создаем ShellCommand.php для интерактивной оболочки
cat > "$TARGET_DIR/src/Command/ShellCommand.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use CodebaseIntelligence\API\AIModelClientInterface;
use CodebaseIntelligence\Session\SessionManager;
use CodebaseIntelligence\Cache\ResponseCache;
use CodebaseIntelligence\Utils\Logger;
use CodebaseIntelligence\Utils\UsageMetrics;
use Psr\Log\LoggerInterface;

/**
 * Interactive shell for working with Claude
 */
class ShellCommand extends Command
{
    protected static $defaultName = 'shell';
    protected static $defaultDescription = 'Start an interactive shell session with Claude';

    private LoggerInterface $logger;
    private AIModelClientInterface $client;
    private SessionManager $sessionManager;
    private ResponseCache $cache;
    private UsageMetrics $metrics;
    private array $config;

    public function __construct(
        AIModelClientInterface $client,
        array $config,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct();
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger ?? new Logger('shell-command');

        // These will be initialized in execute() when we have the storage path
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption(
                'session-id',
                's',
                InputOption::VALUE_OPTIONAL,
                'Use an existing session ID instead of creating a new one',
                null
            )
            ->addOption(
                'storage-dir',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Directory for storing session data',
                null
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable response caching'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Claude Interactive Shell');

        $sessionId = $input->getOption('session-id');
        $storageDir = $input->getOption('storage-dir') ?? $this->config['storage_dir'] ?? getcwd() . '/.claude';
        $noCache = $input->getOption('no-cache');

        // Initialize components with storage dir
        $this->sessionManager = new SessionManager($this->client, $storageDir, $this->config, $this->logger);
        $this->cache = new ResponseCache(
            array_merge($this->config, ['cache_enabled' => !$noCache]),
            $this->logger
        );
        $this->metrics = new UsageMetrics($storageDir, $this->logger);

        // Create or resume session
        if ($sessionId) {
            try {
                $session = $this->sessionManager->resumeSession($sessionId);
                $io->success("Resumed session: {$sessionId}");
            } catch (\Exception $e) {
                $io->error("Failed to resume session: {$e->getMessage()}");
                $session = $this->sessionManager->createSession('interactive-shell-' . date('YmdHis'));
                $io->note("Created new session instead: {$session['id']}");
            }
        } else {
            $session = $this->sessionManager->createSession('interactive-shell-' . date('YmdHis'));
            $io->success("Created new session: {$session['id']}");
        }

        $sessionId = $session['id'];
        $history = [];

        $io->note([
            'Enter your prompts to send to Claude. Special commands:',
            '  !help - Show this help',
            '  !exit or !quit - Exit the shell',
            '  !history - Show command history',
            '  !clear - Clear the screen',
            '  !thinking <default|extended> - Set thinking mode',
            '  !file <path> - Send a file to Claude',
            '  !dir <path> - Send a directory to Claude',
            '  !session <action> - Session management (list, create, resume, delete)',
        ]);

        $thinkingMode = 'default';

        while (true) {
            $input = $io->ask("Claude> ");

            if (empty($input)) {
                continue;
            }

            // Add to history
            $history[] = $input;

            // Process special commands
            if ($input === '!exit' || $input === '!quit') {
                break;
            } elseif ($input === '!help') {
                $this->showHelp($io);
                continue;
            } elseif ($input === '!history') {
                $this->showHistory($io, $history);
                continue;
            } elseif ($input === '!clear') {
                system('clear');
                continue;
            } elseif (strpos($input, '!thinking') === 0) {
                $parts = explode(' ', $input, 2);
                if (isset($parts[1])) {
                    $mode = strtolower(trim($parts[1]));
                    if ($mode === 'default' || $mode === 'extended') {
                        $thinkingMode = $mode;
                        $io->success("Thinking mode set to: $thinkingMode");
                    } else {
                        $io->error("Invalid thinking mode. Use 'default' or 'extended'");
                    }
                } else {
                    $io->note("Current thinking mode: $thinkingMode");
                }
                continue;
            } elseif (strpos($input, '!file') === 0) {
                $parts = explode(' ', $input, 2);
                if (isset($parts[1])) {
                    $filePath = trim($parts[1]);
                    $this->sendFile($io, $sessionId, $filePath);
                } else {
                    $io->error("Missing file path. Usage: !file <path>");
                }
                continue;
            } elseif (strpos($input, '!dir') === 0) {
                $parts = explode(' ', $input, 2);
                if (isset($parts[1])) {
                    $dirPath = trim($parts[1]);
                    $this->sendDirectory($io, $sessionId, $dirPath);
                } else {
                    $io->error("Missing directory path. Usage: !dir <path>");
                }
                continue;
            } elseif (strpos($input, '!session') === 0) {
                $parts = explode(' ', $input, 3);
                if (isset($parts[1])) {
                    $action = strtolower(trim($parts[1]));
                    $this->handleSessionAction($io, $action, $parts[2] ?? null);
                } else {
                    $io->error("Missing session action. Usage: !session <list|create|resume|delete> [id]");
                }
                continue;
            }

            // Regular prompt - check cache first
            $codeContext = []; // In a real implementation, this would contain current context
            $cachedResponse = $this->cache->getCachedResponse($input, $codeContext);

            if ($cachedResponse) {
                $io->note("Retrieved from cache");
                $this->displayResponse($io, $cachedResponse);
                continue;
            }

            // Send to Claude with progress indication
            $io->writeln("<comment>Sending to Claude...</comment>");

            try {
                $startTime = microtime(true);
                $response = $this->client->sendPrompt($sessionId, $input, $thinkingMode === 'extended' ? 'extended_thinking' : 'default');
                $endTime = microtime(true);
                $responseTime = (int) (($endTime - $startTime) * 1000); // Convert to milliseconds

                // Add response time to the response for metrics
                $response['metrics'] = [
                    'response_time' => $responseTime
                ];

                // Cache the response for future use
                $this->cache->cacheResponse($input, $codeContext, $response);

                // Record metrics
                $this->metrics->recordPromptMetrics($sessionId, $input, $response, null, null, $responseTime);

                // Display response
                $this->displayResponse($io, $response);
            } catch (\Exception $e) {
                $io->error("Error: {$e->getMessage()}");
            }
        }

        $io->success("Exiting shell. Session ID: {$sessionId}");
        return Command::SUCCESS;
    }

    /**
     * Display Claude's response
     */
    private function displayResponse(SymfonyStyle $io, array $response): void
    {
        $content = $response['content'] ?? '';

        if (empty($content)) {
            $io->warning("Empty response received");
            return;
        }

        $io->writeln("\n<info>Claude:</info>");
        $io->writeln($content);
        $io->newLine();

        // Show metadata if available
        if (isset($response['metrics'])) {
            $io->writeln(sprintf(
                "<comment>Response time: %d ms</comment>",
                $response['metrics']['response_time']
            ));
        }
    }

    /**
     * Show help message
     */
    private function showHelp(SymfonyStyle $io): void
    {
        $io->section('Available Commands');
        $io->listing([
            '!help - Show this help',
            '!exit or !quit - Exit the shell',
            '!history - Show command history',
            '!clear - Clear the screen',
            '!thinking <default|extended> - Set thinking mode',
            '!file <path> - Send a file to Claude',
            '!dir <path> - Send a directory to Claude',
            '!session list - List available sessions',
            '!session create [name] - Create a new session',
            '!session resume <id> - Resume an existing session',
            '!session delete <id> - Delete a session',
        ]);
    }

    /**
     * Show command history
     */
    private function showHistory(SymfonyStyle $io, array $history): void
    {
        $io->section('Command History');

        if (empty($history)) {
            $io->writeln('No history yet');
            return;
        }

        foreach (array_slice($history, 0, -1) as $i => $cmd) {
            $io->writeln(sprintf('%d: %s', $i + 1, $cmd));
        }
    }

    /**
     * Send a file to Claude
     */
    private function sendFile(SymfonyStyle $io, string $sessionId, string $filePath): void
    {
        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return;
        }

        try {
            $io->writeln("<comment>Sending file to Claude...</comment>");
            $this->client->sendFile($sessionId, $filePath);
            $io->success("File sent: " . basename($filePath));
        } catch (\Exception $e) {
            $io->error("Failed to send file: {$e->getMessage()}");
        }
    }

    /**
     * Send a directory to Claude
     */
    private function sendDirectory(SymfonyStyle $io, string $sessionId, string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            $io->error("Directory not found: $dirPath");
            return;
        }

        try {
            $io->writeln("<comment>Sending directory to Claude...</comment>");
            $this->client->sendDirectory($sessionId, $dirPath);
            $io->success("Directory sent: " . basename($dirPath));
        } catch (\Exception $e) {
            $io->error("Failed to send directory: {$e->getMessage()}");
        }
    }

    /**
     * Handle session-related actions
     */
    private function handleSessionAction(SymfonyStyle $io, string $action, ?string $param = null): void
    {
        switch ($action) {
            case 'list':
                $sessions = $this->sessionManager->listSessions();
                $io->section('Available Sessions');

                if (empty($sessions)) {
                    $io->writeln('No sessions found');
                    return;
                }

                $rows = [];
                foreach ($sessions as $session) {
                    $rows[] = [
                        $session['id'],
                        $session['name'],
                        isset($session['created_at']) ? date('Y-m-d H:i:s', strtotime($session['created_at'])) : 'N/A'
                    ];
                }

                $io->table(['ID', 'Name', 'Created At'], $rows);
                break;

            case 'create':
                $name = $param ?? 'interactive-shell-' . date('YmdHis');
                $session = $this->sessionManager->createSession($name);
                $io->success("Created new session: {$session['id']} (name: $name)");
                break;

            case 'resume':
                if (empty($param)) {
                    $io->error("Session ID is required for resume action");
                    return;
                }

                try {
                    $session = $this->sessionManager->resumeSession($param);
                    $io->success("Resumed session: {$param}");
                } catch (\Exception $e) {
                    $io->error("Failed to resume session: {$e->getMessage()}");
                }
                break;

            case 'delete':
                if (empty($param)) {
                    $io->error("Session ID is required for delete action");
                    return;
                }

                try {
                    $result = $this->sessionManager->deleteSession($param);
                    if ($result) {
                        $io->success("Deleted session: {$param}");
                    } else {
                        $io->error("Failed to delete session");
                    }
                } catch (\Exception $e) {
                    $io->error("Failed to delete session: {$e->getMessage()}");
                }
                break;

            default:
                $io->error("Unknown session action: $action");
                break;
        }
    }
}
EOT

echo "Создание командных скриптов завершено."