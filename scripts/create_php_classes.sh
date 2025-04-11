#!/bin/bash

# Скрипт для создания PHP классов в директориях Analysis, Budget, Cache и Session

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание PHP классов в директориях $TARGET_DIR/src/{Analysis,Budget,Cache,Session}..."

# Создаем необходимые директории, если они не существуют
mkdir -p "$TARGET_DIR/src/Analysis"
mkdir -p "$TARGET_DIR/src/Budget"
mkdir -p "$TARGET_DIR/src/Cache"
mkdir -p "$TARGET_DIR/src/Session"

# Создаем LanguageAnalyzerInterface.php
cat > "$TARGET_DIR/src/Analysis/LanguageAnalyzerInterface.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

/**
 * Interface for language-specific code analyzers
 */
interface LanguageAnalyzerInterface
{
    /**
     * Get regex patterns for identifying semantic units
     */
    public function getSemanticPatterns(): array;

    /**
     * Get patterns for files to exclude from analysis
     */
    public function getExclusionPatterns(): array;

    /**
     * Identify semantic units in code content
     */
    public function identifySemanticUnits(string $content): array;

    /**
     * Get default file extensions for this language
     */
    public function getFileExtensions(): array;

    /**
     * Extract dependencies from the code
     */
    public function extractDependencies(string $content): array;
}
EOT
echo "✓ Создан класс LanguageAnalyzerInterface.php"

# Создаем PhpAnalyzer.php
cat > "$TARGET_DIR/src/Analysis/PhpAnalyzer.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

/**
 * PHP-specific code analyzer
 */
class PhpAnalyzer implements LanguageAnalyzerInterface
{
    /**
     * Get semantic patterns for PHP code
     */
    public function getSemanticPatterns(): array
    {
        return [
            'class_pattern' => '/\s*(abstract\s+|final\s+)?class\s+\w+(\s+extends\s+\w+)?(\s+implements\s+[\w\s,]+)?\s*{/m',
            'function_pattern' => '/\s*(public|protected|private)?\s*(static\s+)?function\s+\w+\s*\(/m',
            'section_pattern' => '/\/\/\s*SECTION:\s*.+$/m',
            'comment_pattern' => '/\/\*\*[\s\S]*?\*\//m',
            'namespace_pattern' => '/\s*namespace\s+[\w\\\\]+;/m',
            'use_pattern' => '/\s*use\s+[\w\\\\]+(\s+as\s+\w+)?;/m',
            'interface_pattern' => '/\s*interface\s+\w+(\s+extends\s+[\w\s,]+)?\s*{/m',
            'trait_pattern' => '/\s*trait\s+\w+\s*{/m',
            'enum_pattern' => '/\s*enum\s+\w+\s*:\s*\w+\s*{/m',
        ];
    }

    /**
     * Get exclusion patterns for PHP projects
     */
    public function getExclusionPatterns(): array
    {
        return [
            'vendor/*',
            'node_modules/*',
            'tests/*',
            'var/*',
            'cache/*',
            'logs/*',
            '.git/*',
        ];
    }

    /**
     * Identify semantic units in PHP code
     */
    public function identifySemanticUnits(string $content): array
    {
        $units = [];
        $patterns = $this->getSemanticPatterns();

        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $units[] = [
                        'position' => $match[1],
                        'type' => $type,
                        'content' => $match[0]
                    ];
                }
            }
        }

        // Sort units by position
        usort($units, fn($a, $b) => $a['position'] <=> $b['position']);

        return $units;
    }

    /**
     * Get default file extensions for PHP
     */
    public function getFileExtensions(): array
    {
        return ['php'];
    }

    /**
     * Extract dependencies from PHP code
     */
    public function extractDependencies(string $content): array
    {
        $dependencies = [];

        // Extract namespace declarations
        if (preg_match('/namespace\s+([\w\\\\]+);/m', $content, $matches)) {
            $dependencies['namespace'] = $matches[1];
        }

        // Extract use statements
        if (preg_match_all('/use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $class = $match[1];
                $alias = $match[2] ?? null;

                $dependencies['imports'][] = [
                    'class' => $class,
                    'alias' => $alias
                ];
            }
        }

        // Extract class/interface/trait extends and implements
        if (preg_match('/class\s+\w+\s+extends\s+([\w\\\\]+)/m', $content, $matches)) {
            $dependencies['extends'] = $matches[1];
        }

        if (preg_match('/class\s+\w+(?:\s+extends\s+[\w\\\\]+)?\s+implements\s+([\w\\\\,\s]+)/m', $content, $matches)) {
            $implements = array_map('trim', explode(',', $matches[1]));
            $dependencies['implements'] = $implements;
        }

        return $dependencies;
    }
}
EOT
echo "✓ Создан класс PhpAnalyzer.php"

# Создаем SemanticChunker.php (сокращенная версия для примера)
cat > "$TARGET_DIR/src/Analysis/SemanticChunker.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Amp;

/**
 * Semantically chunks codebase for efficient token usage
 */
class SemanticChunker
{
    private LoggerInterface $logger;
    private array $config;
    private LanguageAnalyzerInterface $languageAnalyzer;

    public function __construct(
        array $config,
        ?LanguageAnalyzerInterface $languageAnalyzer = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new Logger('semantic-chunker');

        // Create appropriate language analyzer based on config
        if ($languageAnalyzer === null) {
            $language = $this->config['codebase_language'] ?? 'php';

            $this->languageAnalyzer = match ($language) {
                'php' => new PhpAnalyzer(),
                'python' => new PythonAnalyzer(),
                'golang' => new GolangAnalyzer(),
                default => new PhpAnalyzer()
            };
        } else {
            $this->languageAnalyzer = $languageAnalyzer;
        }
    }

    /**
     * Creates semantic chunks from a codebase
     */
    public function createSemanticChunks(string $codebasePath, int $maxChunkSize = 2000): array
    {
        $this->logger->info("Creating semantic chunks for codebase", ['path' => $codebasePath]);

        // Implementation details...
        return [];
    }

    // Другие методы класса...
}
EOT
echo "✓ Создан класс SemanticChunker.php"

# Создаем DifferentialUpdater.php (сокращенная версия)
cat > "$TARGET_DIR/src/Analysis/DifferentialUpdater.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates differential updates for efficient codebase updating
 */
class DifferentialUpdater
{
    private LoggerInterface $logger;
    private Filesystem $filesystem;
    private string $codebasePath;
    private string $versionFile;
    private string $diffDir;

    public function __construct(
        string $codebasePath,
        string $storageDir,
        ?LoggerInterface $logger = null
    ) {
        $this->codebasePath = $codebasePath;
        $this->logger = $logger ?? new Logger('differential-updater');
        $this->filesystem = new Filesystem();

        // Create storage directory if it doesn't exist
        $this->filesystem->mkdir($storageDir, 0755);

        $this->versionFile = $storageDir . '/version_manifest.md5';
        $this->diffDir = $storageDir . '/diffs';

        // Create diffs directory if it doesn't exist
        $this->filesystem->mkdir($this->diffDir, 0755);
    }

    // Методы класса...
}
EOT
echo "✓ Создан класс DifferentialUpdater.php"

# Создаем CodebaseAnalyzer.php
cat > "$TARGET_DIR/src/Analysis/CodebaseAnalyzer.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Analyzes codebase structure and architecture
 */
class CodebaseAnalyzer
{
    private LoggerInterface $logger;
    private string $codebasePath;
    private array $config;
    private LanguageAnalyzerInterface $languageAnalyzer;

    public function __construct(
        string $codebasePath,
        array $config = [],
        ?LanguageAnalyzerInterface $languageAnalyzer = null,
        ?LoggerInterface $logger = null
    ) {
        $this->codebasePath = $codebasePath;
        $this->config = $config;
        $this->logger = $logger ?? new Logger('codebase-analyzer');

        // Create language analyzer if not provided
        if ($languageAnalyzer === null) {
            $language = $config['codebase_language'] ?? 'php';

            $this->languageAnalyzer = match ($language) {
                'php' => new PhpAnalyzer(),
                'python' => new PythonAnalyzer(),
                'golang' => new GolangAnalyzer(),
                default => new PhpAnalyzer()
            };
        } else {
            $this->languageAnalyzer = $languageAnalyzer;
        }
    }

    /**
     * Analyze the codebase and generate a report
     */
    public function analyze(): array
    {
        $this->logger->info("Analyzing codebase", ['path' => $this->codebasePath]);

        // This would be a comprehensive implementation in the real class
        // Here we'll just return a skeleton of the analysis result

        return [
            'structure' => $this->analyzeStructure(),
            'dependencies' => $this->analyzeDependencies(),
            'complexity' => $this->analyzeComplexity(),
            'core_components' => $this->identifyCoreComponents(),
        ];
    }

    /**
     * Analyze codebase structure
     */
    private function analyzeStructure(): array
    {
        // Implementation would map the directory structure and file organization
        return [
            'directories' => 0,
            'files' => 0,
            'languages' => [],
        ];
    }

    /**
     * Analyze dependencies between components
     */
    private function analyzeDependencies(): array
    {
        // Implementation would create a dependency graph
        return [
            'dependency_graph' => [],
            'external_dependencies' => [],
        ];
    }

    /**
     * Analyze code complexity
     */
    private function analyzeComplexity(): array
    {
        // Implementation would calculate complexity metrics
        return [
            'average_complexity' => 0,
            'hotspots' => [],
        ];
    }

    /**
     * Identify core components by analyzing dependencies
     */
    private function identifyCoreComponents(): array
    {
        // Implementation would identify critical parts of the codebase
        return [
            'core_files' => [],
            'core_modules' => [],
        ];
    }
}
EOT
echo "✓ Создан класс CodebaseAnalyzer.php"

# Создаем PythonAnalyzer.php
cat > "$TARGET_DIR/src/Analysis/PythonAnalyzer.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

/**
 * Python-specific code analyzer
 */
class PythonAnalyzer implements LanguageAnalyzerInterface
{
    /**
     * Get semantic patterns for Python code
     */
    public function getSemanticPatterns(): array
    {
        return [
            'class_pattern' => '/\s*class\s+\w+(\s*\(\s*[\w\s,]+\s*\))?\s*:/m',
            'function_pattern' => '/\s*def\s+\w+\s*\(/m',
            'section_pattern' => '/\s*#\s*SECTION:\s*.+$/m',
            'comment_pattern' => '/"""[\s\S]*?"""/m',
            'import_pattern' => '/\s*(?:import|from)\s+[\w\.]+(?:\s+import\s+[\w\.\*]+(?:\s+as\s+\w+)?)?/m',
            'decorator_pattern' => '/\s*@[\w\.]+(?:\s*\([^)]*\))?/m',
            'async_function_pattern' => '/\s*async\s+def\s+\w+\s*\(/m',
            'with_pattern' => '/\s*with\s+.+:/m',
            'try_pattern' => '/\s*try\s*:/m',
        ];
    }

    /**
     * Get exclusion patterns for Python projects
     */
    public function getExclusionPatterns(): array
    {
        return [
            'venv/*',
            '.venv/*',
            'env/*',
            '__pycache__/*',
            '*.pyc',
            '.git/*',
            'dist/*',
            'build/*',
            '*.egg-info/*',
        ];
    }

    /**
     * Identify semantic units in Python code
     */
    public function identifySemanticUnits(string $content): array
    {
        // Аналогично реализации в PhpAnalyzer
        return [];
    }

    /**
     * Get default file extensions for Python
     */
    public function getFileExtensions(): array
    {
        return ['py'];
    }

    /**
     * Extract dependencies from Python code
     */
    public function extractDependencies(string $content): array
    {
        // Реализация извлечения зависимостей для Python
        return [];
    }
}
EOT
echo "✓ Создан класс PythonAnalyzer.php"

# Создаем GolangAnalyzer.php
cat > "$TARGET_DIR/src/Analysis/GolangAnalyzer.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

/**
 * Golang-specific code analyzer
 */
class GolangAnalyzer implements LanguageAnalyzerInterface
{
    /**
     * Get semantic patterns for Golang code
     */
    public function getSemanticPatterns(): array
    {
        return [
            'package_pattern' => '/\s*package\s+\w+/m',
            'import_pattern' => '/\s*import\s+(?:\(\s*(?:"[^"]+"\s*)+\)|"[^"]+")/m',
            'function_pattern' => '/\s*func\s+(?:\(\s*\w+\s+[\*\w]+\s*\)\s+)?\w+\s*\(/m',
            'struct_pattern' => '/\s*type\s+\w+\s+struct\s*{/m',
            'interface_pattern' => '/\s*type\s+\w+\s+interface\s*{/m',
            'section_pattern' => '/\s*\/\/\s*SECTION:\s*.+$/m',
            'comment_pattern' => '/\/\*[\s\S]*?\*\//m',
            'method_pattern' => '/\s*func\s+\(\s*\w+\s+[\*\w]+\s*\)\s+\w+\s*\(/m',
            'const_pattern' => '/\s*const\s+(?:\(\s*(?:\w+(?:\s+[\w\.]+)?\s*=\s*[^=\n]+\s*)+\)|\w+(?:\s+[\w\.]+)?\s*=\s*[^=\n]+)/m',
            'var_pattern' => '/\s*var\s+(?:\(\s*(?:\w+(?:\s+[\w\.]+)?\s*(?:=\s*[^=\n]+)?\s*)+\)|\w+(?:\s+[\w\.]+)?\s*(?:=\s*[^=\n]+)?)/m',
        ];
    }

    /**
     * Get exclusion patterns for Golang projects
     */
    public function getExclusionPatterns(): array
    {
        return [
            'vendor/*',
            '.git/*',
            'bin/*',
            'pkg/*',
            '*.test',
            '*.pb.go', // Generated protobuf files
            'node_modules/*',
        ];
    }

    /**
     * Identify semantic units in Golang code
     */
    public function identifySemanticUnits(string $content): array
    {
        // Аналогично реализации в PhpAnalyzer
        return [];
    }

    /**
     * Get default file extensions for Golang
     */
    public function getFileExtensions(): array
    {
        return ['go'];
    }

    /**
     * Extract dependencies from Golang code
     */
    public function extractDependencies(string $content): array
    {
        // Реализация извлечения зависимостей для Go
        return [];
    }
}
EOT
echo "✓ Создан класс GolangAnalyzer.php"

# Создаем CostEstimator.php в директории Budget
cat > "$TARGET_DIR/src/Budget/CostEstimator.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Budget;

use CodebaseIntelligence\Utils\Logger;
use CodebaseIntelligence\Analysis\SemanticChunker;
use Psr\Log\LoggerInterface;

/**
 * Estimates costs for using Claude 3.7 Sonnet with a codebase
 */
class CostEstimator
{
    private LoggerInterface $logger;
    private array $config;
    private array $modelPricing = [
        'claude-3-7-sonnet-20250219' => [
            'input_tokens_per_million' => 8.0,   // $8 per 1M input tokens
            'output_tokens_per_million' => 24.0, // $24 per 1M output tokens
        ],
        'claude-3-5-sonnet-20240620' => [
            'input_tokens_per_million' => 3.0,  // $3 per 1M input tokens
            'output_tokens_per_million' => 15.0, // $15 per 1M output tokens
        ],
        'claude-3-opus-20240229' => [
            'input_tokens_per_million' => 15.0,  // $15 per 1M input tokens
            'output_tokens_per_million' => 75.0, // $75 per 1M output tokens
        ],
        'claude-3-5-haiku-20240307' => [
            'input_tokens_per_million' => 0.25,  // $0.25 per 1M input tokens
            'output_tokens_per_million' => 1.25, // $1.25 per 1M output tokens
        ]
    ];

    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new Logger('cost-estimator');
    }

    /**
     * Estimates cost for initial codebase ingestion
     */
    public function estimateIngestionCost(string $codebasePath, int $inputRatio = 90, int $outputRatio = 10): array
    {
        // Основная логика расчёта стоимости...
        return [];
    }

    /**
     * Estimates cost for daily operation based on team size and activity
     */
    public function estimateDailyOperationCost(
        int $teamSize = 2,
        int $changesPerDay = 20,
        int $requestsPerChange = 3,
        float $averageTokensPerRequest = 500
    ): array {
        // Логика расчёта ежедневных затрат...
        return [];
    }

    // Другие методы класса...
}
EOT
echo "✓ Создан класс CostEstimator.php"

# Создаем ResponseCache.php в директории Cache
cat > "$TARGET_DIR/src/Cache/ResponseCache.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Cache;

use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Context-aware caching for Claude responses to reduce token usage
 */
class ResponseCache
{
    private LoggerInterface $logger;
    private AdapterInterface $cache;
    private bool $enabled;
    private int $ttl;
    private string $namespace;

    public function __construct(
        array $config = [],
        ?LoggerInterface $logger = null,
        ?AdapterInterface $cache = null
    ) {
        $this->logger = $logger ?? new Logger('response-cache');
        $this->enabled = $config['cache_enabled'] ?? true;
        $this->ttl = (int) ($config['cache_ttl'] ?? 604800); // 1 week default
        $this->namespace = $config['cache_namespace'] ?? 'claude_cache';

        if ($cache === null) {
            $cacheStorage = $config['cache_storage'] ?? 'filesystem';

            if ($cacheStorage === 'redis') {
                // Инициализация Redis кеша...
            } else {
                // Инициализация файлового кеша...
            }
        } else {
            $this->cache = $cache;
        }
    }

    /**
     * Generates a cache key for a given prompt and context
     */
    public function generateCacheKey(string $prompt, array $codeContext = []): string
    {
        // Логика генерации ключа кеша...
        return '';
    }

    /**
     * Gets cached response if available
     */
    public function getCachedResponse(string $prompt, array $codeContext = []): ?array
    {
        // Логика получения кешированного ответа...
        return null;
    }

    /**
     * Caches a response for future use
     */
    public function cacheResponse(string $prompt, array $codeContext, array $response): void
    {
        // Логика кеширования ответа...
    }

    // Другие методы класса...
}
EOT
echo "✓ Создан класс ResponseCache.php"

# Создаем SessionManager.php в директории Session
cat > "$TARGET_DIR/src/Session/SessionManager.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Session;

use CodebaseIntelligence\API\ClaudeClient;
use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Manages Claude sessions for optimal usage and cost efficiency
 */
class SessionManager
{
    private ClaudeClient $claudeClient;
    private LoggerInterface $logger;
    private string $storageDir;
    private string $sessionPrefix;
    private int $sessionTtl;
    private int $tokenBudget;
    private float $offPeakDiscount;
    private Filesystem $filesystem;

    public function __construct(
        ClaudeClient $claudeClient,
        string $storageDir,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->claudeClient = $claudeClient;
        $this->storageDir = $storageDir;
        $this->logger = $logger ?? new Logger('session-manager');
        $this->filesystem = new Filesystem();

        // Create storage directory if it doesn't exist
        $this->filesystem->mkdir($storageDir, 0755);

        // Initialize configuration
        $this->sessionPrefix = $config['session_prefix'] ?? 'claude-session';
        $this->sessionTtl = (int) ($config['session_ttl'] ?? 28800); // 8 hours default
        $this->tokenBudget = (int) ($config['token_budget_daily'] ?? 50000);
        $this->offPeakDiscount = (float) ($config['off_peak_discount'] ?? 0.7); // 30% discount default
    }

    /**
     * Creates a new session with optimized parameters
     */
    public function createSession(string $name = '', bool $useOffPeakOptimization = true): array
    {
        // Логика создания сессии...
        return [];
    }

    /**
     * Resumes an existing session
     */
    public function resumeSession(string $sessionId = ''): array
    {
        // Логика возобновления сессии...
        return [];
    }

    // Другие методы класса...
}
EOT
echo "✓ Создан класс SessionManager.php"

echo "Создание PHP классов завершено успешно."