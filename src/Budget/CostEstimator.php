<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Budget;

use CodebaseIntelligence\Utils\Logger;
use CodebaseIntelligence\Analysis\SemanticChunker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

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
            'input_tokens_per_million' => 3.0,   // $3 per 1M input tokens
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
        $this->logger->info("Estimating ingestion cost", ['path' => $codebasePath]);

        // Get codebase size
        $codebaseSize = $this->getCodebaseSize($codebasePath);
        $estimatedFiles = $this->countFiles($codebasePath);

        // Estimate tokens
        // Approximately 1 token per 4 characters for English text
        // For code, it's usually more efficient, but we'll use a conservative estimate
        $totalChars = $codebaseSize;
        $estimatedTokens = (int) ceil($totalChars / 4);

        // Split into input and output tokens
        $inputTokens = (int) ceil($estimatedTokens * ($inputRatio / 100));
        $outputTokens = (int) ceil($estimatedTokens * ($outputRatio / 100));

        // Calculate cost
        $model = $this->config['claude_model'] ?? 'claude-3-7-sonnet-20250219';
        $pricing = $this->modelPricing[$model] ?? $this->modelPricing['claude-3-7-sonnet-20250219'];

        $inputCost = ($inputTokens / 1000000) * $pricing['input_tokens_per_million'];
        $outputCost = ($outputTokens / 1000000) * $pricing['output_tokens_per_million'];
        $totalCost = $inputCost + $outputCost;

        $result = [
            'codebase_size' => $codebaseSize,
            'estimated_files' => $estimatedFiles,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'estimated_cost' => $totalCost,
        ];

        // Calculate optimized cost with semantic chunking
        // Typically reduces token count by 30-40%
        $optimizationFactor = 0.6; // 40% reduction
        $optimizedTokens = (int) ceil(($inputTokens + $outputTokens) * $optimizationFactor);
        $optimizedInputTokens = (int) ceil($optimizedTokens * ($inputRatio / 100));
        $optimizedOutputTokens = (int) ceil($optimizedTokens * ($outputRatio / 100));

        $optimizedInputCost = ($optimizedInputTokens / 1000000) * $pricing['input_tokens_per_million'];
        $optimizedOutputCost = ($optimizedOutputTokens / 1000000) * $pricing['output_tokens_per_million'];
        $optimizedTotalCost = $optimizedInputCost + $optimizedOutputCost;

        $result['optimized_tokens'] = $optimizedTokens;
        $result['optimized_cost'] = $optimizedTotalCost;

        $this->logger->info("Ingestion cost estimated", [
            'total_tokens' => $result['total_tokens'],
            'cost' => $result['estimated_cost'],
            'optimized_cost' => $result['optimized_cost']
        ]);

        return $result;
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
        $this->logger->info("Estimating daily operation cost", [
            'team_size' => $teamSize,
            'changes_per_day' => $changesPerDay
        ]);

        // Estimate daily prompts
        $dailyPrompts = $teamSize * $changesPerDay * $requestsPerChange;

        // Estimate tokens per prompt (input + output)
        $tokensPerPrompt = $averageTokensPerRequest * 1.5; // Input + output

        // Calculate daily tokens
        $dailyTokens = $dailyPrompts * $tokensPerPrompt;

        // Calculate cost
        $model = $this->config['claude_model'] ?? 'claude-3-7-sonnet-20250219';
        $pricing = $this->modelPricing[$model] ?? $this->modelPricing['claude-3-7-sonnet-20250219'];

        // Assume 40/60 split between input and output tokens
        $inputTokens = $dailyTokens * 0.4;
        $outputTokens = $dailyTokens * 0.6;

        $inputCost = ($inputTokens / 1000000) * $pricing['input_tokens_per_million'];
        $outputCost = ($outputTokens / 1000000) * $pricing['output_tokens_per_million'];
        $dailyCost = $inputCost + $outputCost;

        // Calculate monthly cost (21 work days)
        $monthlyCost = $dailyCost * 21;

        $result = [
            'daily_prompts' => $dailyPrompts,
            'tokens_per_prompt' => $tokensPerPrompt,
            'daily_tokens' => $dailyTokens,
            'daily_cost' => $dailyCost,
            'monthly_cost' => $monthlyCost,
            'cost_per_developer' => $monthlyCost / $teamSize,
        ];

        // Calculate optimized cost with caching and scheduling
        // Typically reduces token count by 50-70%
        $optimizationFactor = 0.4; // 60% reduction
        $optimizedDailyTokens = $dailyTokens * $optimizationFactor;

        // Assume same 40/60 split between input and output tokens
        $optimizedInputTokens = $optimizedDailyTokens * 0.4;
        $optimizedOutputTokens = $optimizedDailyTokens * 0.6;

        $optimizedInputCost = ($optimizedInputTokens / 1000000) * $pricing['input_tokens_per_million'];
        $optimizedOutputCost = ($optimizedOutputTokens / 1000000) * $pricing['output_tokens_per_million'];
        $optimizedDailyCost = $optimizedInputCost + $optimizedOutputCost;

        // Calculate optimized monthly cost (21 work days)
        $optimizedMonthlyCost = $optimizedDailyCost * 21;

        $result['optimized_daily_tokens'] = $optimizedDailyTokens;
        $result['optimized_daily_cost'] = $optimizedDailyCost;
        $result['optimized_monthly_cost'] = $optimizedMonthlyCost;
        $result['optimized_cost_per_developer'] = $optimizedMonthlyCost / $teamSize;

        $this->logger->info("Daily operation cost estimated", [
            'daily_tokens' => $result['daily_tokens'],
            'daily_cost' => $result['daily_cost'],
            'monthly_cost' => $result['monthly_cost'],
            'optimized_monthly_cost' => $result['optimized_monthly_cost']
        ]);

        return $result;
    }

    /**
     * Gets total codebase size in bytes
     */
    private function getCodebaseSize(string $codebasePath): int
    {
        $finder = new Finder();
        $finder->files()->in($codebasePath);

        // Apply include/exclude patterns from config
        if (!empty($this->config['codebase_include_patterns'])) {
            $includePatterns = explode(',', $this->config['codebase_include_patterns']);
            $finder->name($includePatterns);
        }

        if (!empty($this->config['codebase_exclude_patterns'])) {
            $excludePatterns = explode(',', $this->config['codebase_exclude_patterns']);
            foreach ($excludePatterns as $pattern) {
                $finder->notPath($pattern);
            }
        }

        $totalSize = 0;
        foreach ($finder as $file) {
            $totalSize += $file->getSize();
        }

        return $totalSize;
    }

    /**
     * Counts total files in codebase
     */
    private function countFiles(string $codebasePath): int
    {
        $finder = new Finder();
        $finder->files()->in($codebasePath);

        // Apply include/exclude patterns from config
        if (!empty($this->config['codebase_include_patterns'])) {
            $includePatterns = explode(',', $this->config['codebase_include_patterns']);
            $finder->name($includePatterns);
        }

        if (!empty($this->config['codebase_exclude_patterns'])) {
            $excludePatterns = explode(',', $this->config['codebase_exclude_patterns']);
            foreach ($excludePatterns as $pattern) {
                $finder->notPath($pattern);
            }
        }

        return $finder->count();
    }
}