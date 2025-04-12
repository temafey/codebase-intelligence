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
    private array $dependenciesCache = [];
    private int $maxRecursionDepth;

    public function __construct(
        array $config,
        ?LanguageAnalyzerInterface $languageAnalyzer = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new Logger('semantic-chunker');
        $this->maxRecursionDepth = $config['max_recursion_depth'] ?? 10;

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
        $startTime = microtime(true);
        $this->logger->info("Creating semantic chunks for codebase", ['path' => $codebasePath]);

        // Find all files in the codebase
        $files = $this->findCodeFiles($codebasePath);
        $this->logger->info("Found files for chunking", ['count' => count($files)]);

        if (empty($files)) {
            $this->logger->warning("No files found for chunking", ['path' => $codebasePath]);
            return [];
        }

        // Extract semantic units from files
        $allUnits = $this->extractSemanticUnits($files);
        $this->logger->info("Extracted semantic units", ['count' => count($allUnits)]);

        if (empty($allUnits)) {
            $this->logger->warning("No semantic units extracted", ['files_processed' => count($files)]);
            return [];
        }

        // Group semantic units by related concepts
        $relatedGroups = $this->groupRelatedUnits($allUnits);
        $this->logger->info("Grouped related units", ['groups' => count($relatedGroups)]);

        // Create chunks from the groups
        $chunks = $this->createChunksFromGroups($relatedGroups, $maxChunkSize);
        $processingTime = microtime(true) - $startTime;

        $this->logger->info("Created semantic chunks", [
            'chunks' => count($chunks),
            'processing_time' => round($processingTime, 2) . 's',
            'avg_chunk_size' => count($chunks) > 0 ?
                round(array_sum(array_column($chunks, 'token_estimate')) / count($chunks)) : 0
        ]);

        return $chunks;
    }

    /**
     * Finds all relevant code files in the codebase
     */
    private function findCodeFiles(string $codebasePath): array
    {
        if (!is_dir($codebasePath)) {
            $this->logger->error("Codebase path is not a valid directory", ['path' => $codebasePath]);
            return [];
        }

        try {
            $finder = new Finder();
            $finder->files()->in($codebasePath);

            // Apply include patterns
            if (isset($this->config['codebase_include_patterns'])) {
                $includePatterns = explode(',', $this->config['codebase_include_patterns']);
                $finder->name($includePatterns);
            } else {
                // Default to language-specific extensions
                $extensions = $this->languageAnalyzer->getFileExtensions();
                $patterns = array_map(fn($ext) => "*.$ext", $extensions);
                $finder->name($patterns);
            }

            // Apply exclude patterns
            if (isset($this->config['codebase_exclude_patterns'])) {
                $excludePatterns = explode(',', $this->config['codebase_exclude_patterns']);
                foreach ($excludePatterns as $pattern) {
                    $finder->notPath($pattern);
                }
            }

            // Additionally, apply language-specific exclude patterns
            $langExcludePatterns = $this->languageAnalyzer->getExclusionPatterns();
            foreach ($langExcludePatterns as $pattern) {
                $finder->notPath($pattern);
            }

            $files = [];
            foreach ($finder as $file) {
                $files[] = $file->getRealPath();
            }

            return $files;
        } catch (\Exception $e) {
            $this->logger->error("Error finding code files", [
                'path' => $codebasePath,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Extracts semantic units from all files
     */
    private function extractSemanticUnits(array $files): array
    {
        $allUnits = [];
        $fileCount = count($files);
        $processedCount = 0;
        $errorCount = 0;

        foreach ($files as $file) {
            try {
                $content = file_get_contents($file);
                if ($content === false) {
                    $this->logger->warning("Failed to read file", ['file' => $file]);
                    $errorCount++;
                    continue;
                }

                $relativePath = $this->getRelativePath($file);
                $units = $this->extractUnitsFromFile($file, $content, $relativePath);
                $allUnits = array_merge($allUnits, $units);

                $processedCount++;
                if ($processedCount % 100 == 0 || $processedCount == $fileCount) {
                    $this->logger->debug("Extraction progress", [
                        'processed' => $processedCount,
                        'total' => $fileCount,
                        'percent' => round(($processedCount / $fileCount) * 100, 1) . '%'
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error("Error processing file", [
                    'file' => $file,
                    'error' => $e->getMessage()
                ]);
                $errorCount++;
            }
        }

        $this->logger->info("Extraction completed", [
            'processed' => $processedCount,
            'errors' => $errorCount,
            'units_found' => count($allUnits)
        ]);

        return $allUnits;
    }

    /**
     * Extracts semantic units from a single file
     */
    private function extractUnitsFromFile(string $file, string $content, string $relativePath): array
    {
        // Use language analyzer to identify semantic units
        $semanticUnits = $this->languageAnalyzer->identifySemanticUnits($content);

        // If the analyzer couldn't identify any units, create a single unit for the whole file
        if (empty($semanticUnits)) {
            return [
                [
                    'file' => $file,
                    'relative_path' => $relativePath,
                    'type' => 'file',
                    'content' => $content,
                    'size' => strlen($content),
                    'relations' => [],
                    'tokens' => $this->estimateTokens($content),
                ]
            ];
        }

        // Sort units by position
        usort($semanticUnits, fn($a, $b) => $a['position'] <=> $b['position']);

        // Process each unit to extract its full content
        $units = [];
        $totalUnits = count($semanticUnits);

        for ($i = 0; $i < $totalUnits; $i++) {
            $unit = $semanticUnits[$i];
            $startPos = $unit['position'];

            // Determine end position (either next unit or end of file)
            $endPos = ($i < $totalUnits - 1)
                ? $semanticUnits[$i + 1]['position']
                : strlen($content);

            // Extract unit content
            $unitContent = substr($content, $startPos, $endPos - $startPos);

            // Estimate tokens
            $tokenEstimate = $this->estimateTokens($unitContent);

            // Extract dependencies
            $dependencies = [];
            if (in_array($unit['type'], ['class_pattern', 'function_pattern', 'method_pattern'])) {
                $dependencies = $this->languageAnalyzer->extractDependencies($unitContent);
            }

            $units[] = [
                'file' => $file,
                'relative_path' => $relativePath,
                'type' => $unit['type'],
                'content' => $unitContent,
                'size' => strlen($unitContent),
                'relations' => $dependencies,
                'tokens' => $tokenEstimate,
            ];
        }

        return $units;
    }

    /**
     * Estimate the number of tokens in a text
     */
    private function estimateTokens(string $text): int
    {
        // Improved token estimation algorithm
        // Consider code is more token-efficient than plain text
        $lines = explode("\n", $text);
        $tokenCount = 0;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (empty($trimmedLine) || $trimmedLine[0] === '*' || substr($trimmedLine, 0, 2) === '//') {
                // Comments and empty lines are less token-intensive
                $tokenCount += ceil(strlen($trimmedLine) / 6);
            } else {
                // Code is typically about 4 chars per token
                $tokenCount += ceil(strlen($trimmedLine) / 4);
            }
        }

        return max(1, $tokenCount);
    }

    /**
     * Gets relative path from codebase root
     */
    private function getRelativePath(string $file): string
    {
        $codebasePath = $this->config['codebase_path'] ?? dirname($file);
        $relativePath = str_replace($codebasePath . '/', '', $file);
        return $relativePath;
    }

    /**
     * Группирует семантические единицы по связанным концепциям и зависимостям
     */
    private function groupRelatedUnits(array $units): array
    {
        $this->logger->info("Группировка семантических единиц на основе зависимостей");

        // Создаем граф зависимостей
        $dependencyGraph = [];
        $unitsByIdentifier = [];

        // Шаг 1: Сначала индексируем все юниты и создаем начальный граф
        foreach ($units as $index => $unit) {
            // Создаем уникальный идентификатор для единицы
            $identifier = $this->createUnitIdentifier($unit);
            $unitsByIdentifier[$identifier] = $index;

            // Инициализируем запись в графе зависимостей
            $dependencyGraph[$identifier] = [
                'unit' => $unit,
                'dependencies' => [],
                'dependents' => [],
                'weight' => 0, // Вес узла для определения важности
            ];
        }

        // Шаг 2: Заполняем граф зависимостями
        foreach ($dependencyGraph as $identifier => $node) {
            $unit = $node['unit'];

            // Проверяем разные типы зависимостей
            if (isset($unit['relations']) && is_array($unit['relations'])) {
                foreach ($unit['relations'] as $relationType => $relationData) {
                    // Для различных типов связей (импорты, extends, implements и т.д.)
                    $this->processRelations($dependencyGraph, $identifier, $relationType, $relationData);
                }
            }

            // Дополнительно проверяем зависимости по контенту
            $this->detectContentDependencies($dependencyGraph, $identifier, $unit);
        }

        // Вычисляем веса узлов для определения важности
        $this->calculateNodeWeights($dependencyGraph);

        // Шаг 3: Создаем группы на основе графа зависимостей
        $groups = [];
        $processed = [];

        // Начинаем с "корневых" единиц (классы, файлы) с наивысшим весом
        $rootUnits = $this->findRootUnits($dependencyGraph);

        // Сортируем корневые узлы по весу (важности)
        usort($rootUnits, function($a, $b) use ($dependencyGraph) {
            return $dependencyGraph[$b]['weight'] <=> $dependencyGraph[$a]['weight'];
        });

        foreach ($rootUnits as $root) {
            if (isset($processed[$root])) {
                continue;
            }

            // Собираем связанные единицы, начиная с корневой
            $groupUnits = $this->collectRelatedUnits($dependencyGraph, $root, $processed);

            // Формируем группу из полученных связанных единиц
            if (!empty($groupUnits)) {
                $group = $this->createGroup($groupUnits, $dependencyGraph);
                $groups[] = $group;
            }
        }

        // Шаг 4: Проверяем, что все единицы были включены в группы
        $allProcessed = array_keys($processed);
        $allUnits = array_keys($dependencyGraph);
        $remaining = array_diff($allUnits, $allProcessed);

        // Если остались необработанные единицы, создаем дополнительные группы
        if (!empty($remaining)) {
            $this->logger->info("Остались необработанные единицы", ['count' => count($remaining)]);

            // Группируем оставшиеся единицы по файлам
            $remainingByFile = [];

            foreach ($remaining as $identifier) {
                $unit = $dependencyGraph[$identifier]['unit'];
                $file = $unit['relative_path'];

                if (!isset($remainingByFile[$file])) {
                    $remainingByFile[$file] = [];
                }

                $remainingByFile[$file][] = $identifier;
                $processed[$identifier] = true;
            }

            // Создаем группу для каждого файла
            foreach ($remainingByFile as $file => $identifiers) {
                $groupUnits = [];
                foreach ($identifiers as $id) {
                    $groupUnits[] = $dependencyGraph[$id]['unit'];
                }

                $group = [
                    'name' => "File: " . $file,
                    'units' => $groupUnits,
                    'files' => [$file],
                    'size' => array_sum(array_column($groupUnits, 'size')),
                    'tokens' => array_sum(array_column($groupUnits, 'tokens')),
                ];

                $groups[] = $group;
            }
        }

        // Шаг 5: Оптимизация размера групп
        $groups = $this->optimizeGroupSizes($groups);

        $this->logger->info("Создано групп на основе зависимостей", [
            'count' => count($groups),
            'avg_size' => count($groups) > 0 ?
                round(array_sum(array_column($groups, 'tokens')) / count($groups)) : 0
        ]);

        return $groups;
    }

    /**
     * Вычисляет вес (важность) каждого узла в графе зависимостей
     */
    private function calculateNodeWeights(array &$dependencyGraph): void
    {
        // Итеративно рассчитываем веса узлов
        foreach ($dependencyGraph as $id => &$node) {
            // Базовый вес в зависимости от типа
            $baseWeight = 1;
            $type = $node['unit']['type'];

            if (strpos($type, 'class_pattern') !== false) {
                $baseWeight = 10;
            } elseif (strpos($type, 'interface_pattern') !== false) {
                $baseWeight = 8;
            } elseif (strpos($type, 'trait_pattern') !== false) {
                $baseWeight = 7;
            } elseif (strpos($type, 'function_pattern') !== false) {
                $baseWeight = 5;
            }

            // Добавляем вес за количество зависимых узлов
            $dependentCount = count($node['dependents'] ?? []);
            $dependencyCount = count($node['dependencies'] ?? []);

            // Узлы с много зависимостями и зависимыми узлами имеют больший вес
            $node['weight'] = $baseWeight + ($dependentCount * 2) + $dependencyCount;
        }
    }

    /**
     * Создает уникальный идентификатор для единицы кода
     */
    private function createUnitIdentifier(array $unit): string
    {
        // Извлекаем имя класса/функции/метода из контента
        $name = '';

        if (preg_match('/\b(class|interface|trait|function|enum)\s+(\w+)/i', $unit['content'], $matches)) {
            $name = $matches[2];
        } elseif (preg_match('/function\s+(\w+)/i', $unit['content'], $matches)) {
            $name = $matches[1];
        }

        // Если не смогли извлечь имя, используем хэш от начала контента
        if (empty($name)) {
            $name = 'anon_' . md5(substr($unit['content'], 0, 100));
        }

        return $unit['relative_path'] . '#' . $unit['type'] . '#' . $name;
    }

    /**
     * Обрабатывает отношения между единицами
     */
    private function processRelations(array &$dependencyGraph, string $identifier, string $relationType, $relationData): void
    {
        // Проверка на существование идентификатора в графе зависимостей
        if (!isset($dependencyGraph[$identifier])) {
            $this->logger->warning("Identifier not found in dependency graph", [
                'identifier' => $identifier,
                'relation_type' => $relationType
            ]);
            return;
        }

        switch ($relationType) {
            case 'imports':
            case 'use':
                if (is_array($relationData)) {
                    foreach ($relationData as $import) {
                        $class = is_array($import) ? ($import['class'] ?? null) : $import;
                        if ($class) {
                            $this->addDependency($dependencyGraph, $identifier, $class);
                        }
                    }
                } elseif (is_string($relationData)) {
                    $this->addDependency($dependencyGraph, $identifier, $relationData);
                }
                break;

            case 'extends':
                if (is_string($relationData)) {
                    // Наследование - сильная зависимость
                    $this->addDependency($dependencyGraph, $identifier, $relationData, 2);
                }
                break;

            case 'implements':
                if (is_array($relationData)) {
                    foreach ($relationData as $interface) {
                        if (is_string($interface)) {
                            $this->addDependency($dependencyGraph, $identifier, $interface, 1.5);
                        }
                    }
                }
                break;

            case 'namespace':
                // Namespace создает более слабую связь
                // Не добавляем явную зависимость
                break;
        }
    }

    /**
     * Добавляет зависимость от одной единицы к другой
     */
    private function addDependency(array &$dependencyGraph, string $sourceId, string $targetClass, float $weight = 1.0): void
    {
        // Пытаемся найти целевую единицу по имени класса
        $targetId = $this->findUnitByClassName($dependencyGraph, $targetClass);

        if ($targetId && $sourceId !== $targetId) { // Избегаем самозависимости
            // Проверяем, нет ли уже такой зависимости
            if (!in_array($targetId, $dependencyGraph[$sourceId]['dependencies'] ?? [])) {
                $dependencyGraph[$sourceId]['dependencies'][] = $targetId;
            }

            // Проверяем, нет ли уже такой обратной зависимости
            if (!in_array($sourceId, $dependencyGraph[$targetId]['dependents'] ?? [])) {
                $dependencyGraph[$targetId]['dependents'][] = $sourceId;
            }

            // Увеличиваем вес узлов в зависимости от важности связи
            if (isset($dependencyGraph[$sourceId]['weight'])) {
                $dependencyGraph[$sourceId]['weight'] += $weight;
            }
            if (isset($dependencyGraph[$targetId]['weight'])) {
                $dependencyGraph[$targetId]['weight'] += $weight;
            }
        }
    }

    /**
     * Ищет единицу по имени класса
     */
    private function findUnitByClassName(array $dependencyGraph, string $className): ?string
    {
        // Кэшируем результаты поиска для оптимизации
        $cacheKey = md5($className);
        if (isset($this->dependenciesCache[$cacheKey])) {
            return $this->dependenciesCache[$cacheKey];
        }

        // Извлекаем только имя класса без namespace
        $shortName = substr($className, strrpos($className, '\\') !== false ? strrpos($className, '\\') + 1 : 0);

        // Ищем точное совпадение сначала
        foreach ($dependencyGraph as $id => $node) {
            if (strpos($id, '#class_pattern#' . $shortName . '$') !== false ||
                strpos($id, '#interface_pattern#' . $shortName . '$') !== false ||
                strpos($id, '#trait_pattern#' . $shortName . '$') !== false) {
                $this->dependenciesCache[$cacheKey] = $id;
                return $id;
            }
        }

        // Если точное совпадение не найдено, ищем частичное
        foreach ($dependencyGraph as $id => $node) {
            if (strpos($id, '#class_pattern#' . $shortName) !== false ||
                strpos($id, '#interface_pattern#' . $shortName) !== false ||
                strpos($id, '#trait_pattern#' . $shortName) !== false) {
                $this->dependenciesCache[$cacheKey] = $id;
                return $id;
            }
        }

        // Не найдено
        $this->dependenciesCache[$cacheKey] = null;
        return null;
    }

    /**
     * Обнаруживает дополнительные зависимости на основе анализа контента
     */
    private function detectContentDependencies(array &$dependencyGraph, string $identifier, array $unit): void
    {
        if (!isset($dependencyGraph[$identifier])) {
            return; // Пропускаем, если идентификатор не найден
        }

        $content = $unit['content'];

        // Ищем упоминания других классов в коде
        foreach ($dependencyGraph as $targetId => $targetNode) {
            if ($targetId === $identifier) {
                continue;
            }

            $targetUnit = $targetNode['unit'] ?? null;
            if (!$targetUnit) continue;

            // Извлекаем имя класса/функции целевой единицы
            if (preg_match('/\b(class|interface|trait|function|enum)\s+(\w+)/i', $targetUnit['content'], $matches)) {
                $targetName = $matches[2];

                // Если имя упоминается в контенте текущей единицы и это не совпадение частей слов
                if (preg_match('/\b' . preg_quote($targetName) . '\b/', $content)) {
                    // Исправление: использование правильной переменной $identifier
                    if (!in_array($targetId, $dependencyGraph[$identifier]['dependencies'] ?? [])) {
                        $dependencyGraph[$identifier]['dependencies'][] = $targetId;
                    }
                    if (!in_array($identifier, $dependencyGraph[$targetId]['dependents'] ?? [])) {
                        $dependencyGraph[$targetId]['dependents'][] = $identifier;
                    }
                }
            }
        }
    }

    /**
     * Находит "корневые" единицы для начала обхода графа
     */
    private function findRootUnits(array $dependencyGraph): array
    {
        $roots = [];

        foreach ($dependencyGraph as $id => $node) {
            $unit = $node['unit'] ?? null;
            if (!$unit) continue;

            // Классы, интерфейсы и трейты являются хорошими начальными точками
            if (strpos($id, '#class_pattern#') !== false ||
                strpos($id, '#interface_pattern#') !== false ||
                strpos($id, '#trait_pattern#') !== false) {
                $roots[] = $id;
            }
        }

        // Если корневых единиц не найдено, используем произвольные
        if (empty($roots)) {
            $roots = array_keys($dependencyGraph);
        }

        return $roots;
    }

    /**
     * Собирает связанные единицы, начиная с указанной
     */
    private function collectRelatedUnits(array $dependencyGraph, string $startId, array &$processed, int $depth = 0): array
    {
        // Ограничение глубины рекурсии
        if ($depth > $this->maxRecursionDepth) {
            return [];
        }

        if (!isset($dependencyGraph[$startId])) {
            return []; // Пропускаем, если идентификатор не найден
        }

        $processed[$startId] = true;
        $groupUnits = [$dependencyGraph[$startId]['unit']];
        $collectedUnitIds = [$startId => true]; // Отслеживаем уже добавленные единицы

        // Собираем зависимости
        if (isset($dependencyGraph[$startId]['dependencies'])) {
            foreach ($dependencyGraph[$startId]['dependencies'] as $depId) {
                if (!isset($processed[$depId])) {
                    $depUnits = $this->collectRelatedUnits($dependencyGraph, $depId, $processed, $depth + 1);

                    // Добавляем только те единицы, которые ещё не были добавлены
                    foreach ($depUnits as $unit) {
                        $unitId = $this->createUnitIdentifier($unit);
                        if (!isset($collectedUnitIds[$unitId])) {
                            $groupUnits[] = $unit;
                            $collectedUnitIds[$unitId] = true;
                        }
                    }
                }
            }
        }

        // Собираем зависимые единицы (но с меньшим приоритетом и глубиной)
        if ($depth < 2 && isset($dependencyGraph[$startId]['dependents'])) {
            foreach ($dependencyGraph[$startId]['dependents'] as $depId) {
                if (!isset($processed[$depId])) {
                    $depUnits = $this->collectRelatedUnits($dependencyGraph, $depId, $processed, $depth + 1);

                    // Добавляем только те единицы, которые ещё не были добавлены
                    foreach ($depUnits as $unit) {
                        $unitId = $this->createUnitIdentifier($unit);
                        if (!isset($collectedUnitIds[$unitId])) {
                            $groupUnits[] = $unit;
                            $collectedUnitIds[$unitId] = true;
                        }
                    }
                }
            }
        }

        return $groupUnits;
    }

    /**
     * Создает группу из набора связанных единиц
     */
    private function createGroup(array $units, array $dependencyGraph): array
    {
        if (empty($units)) {
            $this->logger->warning("Attempted to create group with empty units");
            return [
                'name' => 'Empty Group',
                'units' => [],
                'files' => [],
                'size' => 0,
                'tokens' => 0,
            ];
        }

        // Извлекаем уникальные файлы
        $files = [];
        foreach ($units as $unit) {
            if (isset($unit['relative_path'])) {
                $files[] = $unit['relative_path'];
            }
        }
        $files = array_unique($files);

        // Находим ключевую единицу в группе (для имени)
        $mainUnit = reset($units);
        foreach ($units as $unit) {
            if (isset($unit['type']) && strpos($unit['type'], 'class_pattern') !== false) {
                $mainUnit = $unit;
                break;
            }
        }

        // Извлекаем имя для группы
        $groupName = '';
        if (isset($mainUnit['content']) && preg_match('/\b(class|interface|trait)\s+(\w+)/i', $mainUnit['content'], $matches)) {
            $groupName = $matches[2] . ' Component';
        } else {
            // Если нет класса, используем имя первого файла
            $groupName = 'Module: ' . (reset($files) ?: 'Unknown');
        }

        // Вычисляем токены и размер
        $sizes = array_column($units, 'size');
        $tokens = array_column($units, 'tokens');
        $totalSize = !empty($sizes) ? array_sum($sizes) : 0;
        $totalTokens = !empty($tokens) ? array_sum($tokens) : 0;

        return [
            'name' => $groupName,
            'units' => $units,
            'files' => $files,
            'size' => $totalSize,
            'tokens' => $totalTokens,
        ];
    }

    /**
     * Оптимизирует размер групп, разбивая слишком большие группы
     */
    private function optimizeGroupSizes(array $groups): array
    {
        $optimizedGroups = [];
        $maxGroupSize = $this->config['max_group_tokens'] ?? 5000; // Максимальное количество токенов в группе

        foreach ($groups as $group) {
            // Если группа достаточно маленькая, оставляем как есть
            if (($group['tokens'] ?? 0) <= $maxGroupSize) {
                $optimizedGroups[] = $group;
                continue;
            }

            // Иначе разбиваем по файлам
            $unitsByFile = [];
            foreach ($group['units'] as $unit) {
                $file = $unit['relative_path'] ?? 'unknown';
                if (!isset($unitsByFile[$file])) {
                    $unitsByFile[$file] = [];
                }
                $unitsByFile[$file][] = $unit;
            }

            // Для каждого файла создаем отдельную группу
            foreach ($unitsByFile as $file => $units) {
                $subGroup = [
                    'name' => $group['name'] . ' - ' . $file,
                    'units' => $units,
                    'files' => [$file],
                    'size' => array_sum(array_column($units, 'size')),
                    'tokens' => array_sum(array_column($units, 'tokens')),
                ];

                // Если и эта группа слишком большая, можно разбить дальше
                if ($subGroup['tokens'] > $maxGroupSize) {
                    // Разбиваем на блоки примерно по maxGroupSize/2 токенов
                    $chunks = [];
                    $currentChunk = [];
                    $currentTokens = 0;

                    foreach ($units as $unit) {
                        $unitTokens = $unit['tokens'] ?? 0;
                        if ($currentTokens + $unitTokens > $maxGroupSize / 2 && !empty($currentChunk)) {
                            $chunks[] = $currentChunk;
                            $currentChunk = [];
                            $currentTokens = 0;
                        }

                        $currentChunk[] = $unit;
                        $currentTokens += $unitTokens;
                    }

                    // Добавляем последний чанк
                    if (!empty($currentChunk)) {
                        $chunks[] = $currentChunk;
                    }

                    // Создаем группу для каждого чанка
                    for ($i = 0; $i < count($chunks); $i++) {
                        $chunkUnits = $chunks[$i];
                        $chunkGroup = [
                            'name' => $subGroup['name'] . ' (Part ' . ($i + 1) . ')',
                            'units' => $chunkUnits,
                            'files' => [$file],
                            'size' => array_sum(array_column($chunkUnits, 'size')),
                            'tokens' => array_sum(array_column($chunkUnits, 'tokens')),
                        ];

                        $optimizedGroups[] = $chunkGroup;
                    }
                } else {
                    $optimizedGroups[] = $subGroup;
                }
            }
        }

        return $optimizedGroups;
    }

    /**
     * Creates chunks from semantic unit groups
     */
    private function createChunksFromGroups(array $groups, int $maxChunkSize): array
    {
        $chunks = [];
        $currentChunk = [
            'units' => [],
            'files' => [],
            'content' => '',
            'tokens' => 0,
        ];

        // Сортируем группы по размеру (токенам) для оптимального заполнения
        usort($groups, function($a, $b) {
            return $b['tokens'] <=> $a['tokens'];
        });

        foreach ($groups as $group) {
            // If adding this group would exceed max chunk size, finalize the current chunk
            if ($currentChunk['tokens'] > 0 && $currentChunk['tokens'] + $group['tokens'] > $maxChunkSize) {
                $chunks[] = $this->finalizeChunk($currentChunk);
                $currentChunk = [
                    'units' => [],
                    'files' => [],
                    'content' => '',
                    'tokens' => 0,
                ];
            }

            // Add this group to the current chunk
            $currentChunk['units'] = array_merge($currentChunk['units'], $group['units']);
            $currentChunk['files'] = array_merge($currentChunk['files'], $group['files']);
            $currentChunk['tokens'] += $group['tokens'];
        }

        // Add the last chunk if not empty
        if ($currentChunk['tokens'] > 0) {
            $chunks[] = $this->finalizeChunk($currentChunk);
        }

        return $chunks;
    }

    /**
     * Finalizes a chunk for output
     */
    private function finalizeChunk(array $chunk): array
    {
        // Generate content
        $content = "# Code Chunk\n\n";

        // Group units by file
        $unitsByFile = [];
        foreach ($chunk['units'] as $unit) {
            $file = $unit['relative_path'] ?? 'unknown';
            if (!isset($unitsByFile[$file])) {
                $unitsByFile[$file] = [];
            }
            $unitsByFile[$file][] = $unit;
        }

        // Format content
        foreach ($unitsByFile as $file => $units) {
            $content .= "## File: $file\n\n```\n";

            // Сортируем единицы по порядку в исходном файле
            usort($units, function($a, $b) {
                if (isset($a['position']) && isset($b['position'])) {
                    return $a['position'] <=> $b['position'];
                }
                return 0;
            });

            foreach ($units as $unit) {
                $content .= $unit['content'] . "\n";
            }

            $content .= "```\n\n";
        }

        // Deduplicate files list
        $uniqueFiles = array_unique($chunk['files']);

        return [
            'content' => $content,
            'files' => $uniqueFiles,
            'token_estimate' => $chunk['tokens'],
            'unit_count' => count($chunk['units']),
            'file_count' => count($uniqueFiles),
            'checksum' => md5($content)
        ];
    }

    /**
     * Sets maximum recursion depth for collecting related units
     */
    public function setMaxRecursionDepth(int $depth): void
    {
        $this->maxRecursionDepth = $depth;
    }

    /**
     * Clears the dependencies cache
     */
    public function clearCache(): void
    {
        $this->dependenciesCache = [];
    }
}