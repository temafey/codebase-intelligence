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
