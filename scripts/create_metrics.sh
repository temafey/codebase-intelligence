#!/bin/bash

# Скрипт для создания модуля метрик

# Принимаем путь для установки
TARGET_DIR="$1"

echo "Создание модуля метрик в $TARGET_DIR/src/Utils..."

# Создаем директорию Utils, если не существует
mkdir -p "$TARGET_DIR/src/Utils"

# Создаем UsageMetrics.php
cat > "$TARGET_DIR/src/Utils/UsageMetrics.php" << 'EOT'
<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Utils;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tracks usage metrics for cost optimization
 */
class UsageMetrics
{
    private LoggerInterface $logger;
    private string $metricsDir;
    private Filesystem $filesystem;

    public function __construct(
        string $storageDir,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new Logger('usage-metrics');
        $this->metricsDir = $storageDir . '/metrics';
        $this->filesystem = new Filesystem();

        // Ensure metrics directory exists
        $this->filesystem->mkdir($this->metricsDir, 0755);
    }

    /**
     * Records metrics for a prompt and response
     */
    public function recordPromptMetrics(
        string $sessionId,
        string $prompt,
        array $response,
        ?int $promptTokens = null,
        ?int $responseTokens = null,
        ?int $responseTimeMs = null
    ): void {
        $this->logger->debug("Recording prompt metrics", ['session_id' => $sessionId]);

        // If token counts not provided, estimate them
        if ($promptTokens === null) {
            $promptTokens = $this->estimateTokens($prompt);
        }

        if ($responseTokens === null) {
            $responseContent = $response['content'] ?? json_encode($response);
            $responseTokens = $this->estimateTokens($responseContent);
        }

        if ($responseTimeMs === null) {
            $responseTimeMs = $response['metrics']['response_time'] ?? 0;
        }

        // Categorize the prompt
        $promptType = $this->categorizePrompt($prompt);

        // Calculate estimated cost
        $estimatedCost = $this->calculateCost($promptTokens, $responseTokens);

        // Create metrics data
        $metricsData = [
            'timestamp' => time(),
            'session_id' => $sessionId,
            'prompt_tokens' => $promptTokens,
            'response_tokens' => $responseTokens,
            'total_tokens' => $promptTokens + $responseTokens,
            'prompt_type' => $promptType,
            'response_time_ms' => $responseTimeMs,
            'estimated_cost' => $estimatedCost,
            'thinking_mode' => $response['thinking_mode'] ?? 'default',
            'prompt_preview' => substr($prompt, 0, 100),
        ];

        // Append to daily metrics file
        $date = date('Y-m-d');
        $metricsFile = $this->metricsDir . "/metrics-$date.jsonl";

        file_put_contents(
            $metricsFile,
            json_encode($metricsData) . "\n",
            FILE_APPEND
        );

        // Update summary metrics
        $this->updateSummaryMetrics($metricsData);
    }

    /**
     * Updates summary metrics file
     */
    private function updateSummaryMetrics(array $metricsData): void
    {
        $summaryFile = $this->metricsDir . '/summary.json';

        if ($this->filesystem->exists($summaryFile)) {
            $summary = json_decode(file_get_contents($summaryFile), true);
        } else {
            $summary = [
                'total_prompts' => 0,
                'total_tokens' => 0,
                'total_cost' => 0,
                'prompts_by_type' => [],
                'tokens_by_day' => [],
                'average_response_time' => 0,
                'thinking_mode_usage' => [
                    'default' => 0,
                    'extended_thinking' => 0,
                ],
                'first_prompt_time' => time(),
                'last_prompt_time' => 0,
            ];
        }

        // Update summary metrics
        $summary['total_prompts']++;
        $summary['total_tokens'] += $metricsData['total_tokens'];
        $summary['total_cost'] += $metricsData['estimated_cost'];
        $summary['last_prompt_time'] = max($summary['last_prompt_time'], $metricsData['timestamp']);

        // Update prompt types
        $promptType = $metricsData['prompt_type'];
        if (!isset($summary['prompts_by_type'][$promptType])) {
            $summary['prompts_by_type'][$promptType] = 0;
        }
        $summary['prompts_by_type'][$promptType]++;

        // Update tokens by day
        $date = date('Y-m-d', $metricsData['timestamp']);
        if (!isset($summary['tokens_by_day'][$date])) {
            $summary['tokens_by_day'][$date] = 0;
        }
        $summary['tokens_by_day'][$date] += $metricsData['total_tokens'];

        // Update average response time
        $totalTime = $summary['average_response_time'] * ($summary['total_prompts'] - 1);
        $totalTime += $metricsData['response_time_ms'];
        $summary['average_response_time'] = $totalTime / $summary['total_prompts'];

        // Update thinking mode usage
        $thinkingMode = $metricsData['thinking_mode'] ?? 'default';
        if (!isset($summary['thinking_mode_usage'][$thinkingMode])) {
            $summary['thinking_mode_usage'][$thinkingMode] = 0;
        }
        $summary['thinking_mode_usage'][$thinkingMode]++;

        // Write updated summary
        file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT));
    }

    /**
     * Generate a daily usage report
     */
    public function generateDailyReport(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $metricsFile = $this->metricsDir . "/metrics-$date.jsonl";

        if (!$this->filesystem->exists($metricsFile)) {
            $this->logger->warning("No metrics found for date", ['date' => $date]);
            return [
                'date' => $date,
                'total_prompts' => 0,
                'total_tokens' => 0,
                'total_cost' => 0,
                'error' => 'No metrics found for this date',
            ];
        }

        $metrics = [];
        $lines = file($metricsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $metrics[] = json_decode($line, true);
        }

        // Calculate summary statistics
        $totalPrompts = count($metrics);
        $totalTokens = array_sum(array_column($metrics, 'total_tokens'));
        $totalCost = array_sum(array_column($metrics, 'estimated_cost'));

        // Group by prompt type
        $promptTypes = [];
        foreach ($metrics as $metric) {
            $type = $metric['prompt_type'];
            if (!isset($promptTypes[$type])) {
                $promptTypes[$type] = 0;
            }
            $promptTypes[$type]++;
        }

        // Group by session
        $sessions = [];
        foreach ($metrics as $metric) {
            $sessionId = $metric['session_id'];
            if (!isset($sessions[$sessionId])) {
                $sessions[$sessionId] = [
                    'prompts' => 0,
                    'tokens' => 0,
                ];
            }
            $sessions[$sessionId]['prompts']++;
            $sessions[$sessionId]['tokens'] += $metric['total_tokens'];
        }

        // Calculate hourly distribution
        $hourlyUsage = array_fill(0, 24, 0);
        foreach ($metrics as $metric) {
            $hour = (int) date('G', $metric['timestamp']);
            $hourlyUsage[$hour] += $metric['total_tokens'];
        }

        return [
            'date' => $date,
            'total_prompts' => $totalPrompts,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
            'prompt_types' => $promptTypes,
            'sessions' => $sessions,
            'hourly_usage' => $hourlyUsage,
            'average_tokens_per_prompt' => $totalPrompts > 0 ? $totalTokens / $totalPrompts : 0,
        ];
    }

    /**
     * Analyze usage patterns for optimization opportunities
     */
    public function analyzeUsagePatterns(): array
    {
        $summaryFile = $this->metricsDir . '/summary.json';

        if (!$this->filesystem->exists($summaryFile)) {
            $this->logger->warning("No summary metrics found");
            return [
                'status' => 'error',
                'message' => 'No usage data available for analysis',
            ];
        }

        $summary = json_decode(file_get_contents($summaryFile), true);
        $recommendations = [];

        // Check for high token usage
        if ($summary['total_tokens'] > 1000000) {
            $recommendations[] = [
                'type' => 'token_usage',
                'severity' => 'medium',
                'description' => 'High token usage detected. Consider implementing semantic chunking to reduce token count.',
                'estimated_savings' => '30-40%',
            ];
        }

        // Check for repeated prompt types
        foreach ($summary['prompts_by_type'] as $type => $count) {
            if ($count > $summary['total_prompts'] * 0.3) {
                $recommendations[] = [
                    'type' => 'cache_optimization',
                    'severity' => 'high',
                    'description' => "High repetition of '$type' prompts. Enable or optimize caching for this prompt type.",
                    'estimated_savings' => '40-50%',
                ];
            }
        }

        // Check for off-peak usage opportunities
        $offPeakHours = [0, 1, 2, 3, 4, 5, 6];
        $offPeakUsage = 0;
        $totalDailyUsage = 0;

        foreach ($summary['tokens_by_day'] as $day => $tokens) {
            $dailyFile = $this->metricsDir . "/metrics-$day.jsonl";
            if ($this->filesystem->exists($dailyFile)) {
                $lines = file($dailyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $metric = json_decode($line, true);
                    $hour = (int) date('G', $metric['timestamp']);
                    $totalDailyUsage += $metric['total_tokens'];
                    if (in_array($hour, $offPeakHours)) {
                        $offPeakUsage += $metric['total_tokens'];
                    }
                }
            }
        }

        $offPeakPercentage = $totalDailyUsage > 0 ? ($offPeakUsage / $totalDailyUsage) * 100 : 0;
        if ($offPeakPercentage < 20) {
            $recommendations[] = [
                'type' => 'off_peak_scheduling',
                'severity' => 'medium',
                'description' => 'Low off-peak hour usage. Schedule non-interactive tasks during 2am-8am for cost savings.',
                'estimated_savings' => '20-30%',
            ];
        }

        // Check for extended thinking mode optimization
        if (isset($summary['thinking_mode_usage']['extended_thinking'])) {
            $extendedRatio = $summary['thinking_mode_usage']['extended_thinking'] / $summary['total_prompts'];
            if ($extendedRatio > 0.7) {
                $recommendations[] = [
                    'type' => 'thinking_mode_optimization',
                    'severity' => 'medium',
                    'description' => 'High usage of extended thinking mode. Reserve for complex architectural questions only.',
                    'estimated_savings' => '15-25%',
                ];
            }
        }

        return [
            'status' => 'success',
            'analyzed_prompts' => $summary['total_prompts'],
            'analyzed_tokens' => $summary['total_tokens'],
            'date_range' => [
                'from' => date('Y-m-d', $summary['first_prompt_time']),
                'to' => date('Y-m-d', $summary['last_prompt_time']),
            ],
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Categorize prompt based on content
     */
    private function categorizePrompt(string $prompt): string
    {
        $lowerPrompt = strtolower($prompt);

        // Check for common prompt types
        if (preg_match('/архитект|design|структур/i', $lowerPrompt)) {
            return 'architecture';
        } elseif (preg_match('/bug|ошибк|починить|fix|исправить/i', $lowerPrompt)) {
            return 'bugfix';
        } elseif (preg_match('/объясни|explain|как работает|how does/i', $lowerPrompt)) {
            return 'explanation';
        } elseif (preg_match('/оптимизировать|optimize|improve|performance|производительность/i', $lowerPrompt)) {
            return 'optimization';
        } elseif (preg_match('/code review|рецензия|анализ/i', $lowerPrompt)) {
            return 'code_review';
        } elseif (preg_match('/feature|функционал|добавить/i', $lowerPrompt)) {
            return 'feature_development';
        } elseif (preg_match('/refactor|рефакторинг/i', $lowerPrompt)) {
            return 'refactoring';
        }

        return 'general';
    }

    /**
     * Estimate token count
     */
    private function estimateTokens(string $text): int
    {
        // Simple estimation: ~4 characters per token
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Calculate cost estimate
     */
    private function calculateCost(int $promptTokens, int $responseTokens): float
    {
        // Example pricing: $8 per 1M prompt tokens, $24 per 1M completion tokens
        $promptCost = ($promptTokens / 1000000) * 8;
        $responseCost = ($responseTokens / 1000000) * 24;

        return round($promptCost + $responseCost, 4);
    }
}
EOT

echo "Создание модуля метрик завершено."