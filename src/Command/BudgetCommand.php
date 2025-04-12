<?php


declare(strict_types=1);

namespace CodebaseIntelligence\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use CodebaseIntelligence\Budget\CostEstimator;
use CodebaseIntelligence\Utils\UsageMetrics;
use Psr\Log\LoggerInterface;

/**
 * Command for estimating and managing Claude usage costs
 */
#[AsCommand(name: 'budget')]
class BudgetCommand extends Command
{
    protected static $defaultDescription = 'Estimate and manage costs for Claude integration';

    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        parent::__construct();
        $this->config = $config;
        $this->logger = $logger ?? new Logger('budget-command');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument(
                'codebase-path',
                InputArgument::OPTIONAL,
                'Path to the codebase',
                $this->config['codebase_path'] ?? getcwd()
            )
            ->addOption(
                'storage-dir',
                's',
                InputOption::VALUE_OPTIONAL,
                'Directory for storing budget data',
                $this->config['storage_dir'] ?? getcwd() . '/.claude'
            )
            ->addOption(
                'team-size',
                't',
                InputOption::VALUE_OPTIONAL,
                'Number of developers in the team',
                $this->config['team_size'] ?? '2'
            )
            ->addOption(
                'changes-per-day',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Average number of file changes per day',
                $this->config['daily_changes_average'] ?? '20'
            )
            ->addOption(
                'model',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Claude model to use for estimates',
                $this->config['claude_model'] ?? 'claude-3-7-sonnet-20250219'
            )
            ->addOption(
                'current-month',
                null,
                InputOption::VALUE_NONE,
                'Show usage for current month'
            )
            ->addOption(
                'optimizations',
                'o',
                InputOption::VALUE_NONE,
                'Show cost optimization recommendations'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Claude Budget Estimator');

        $codebasePath = $input->getArgument('codebase-path');
        $storageDir = $input->getOption('storage-dir');
        $teamSize = (int)$input->getOption('team-size');
        $changesPerDay = (int)$input->getOption('changes-per-day');
        $model = $input->getOption('model');
        $showCurrentMonth = $input->getOption('current-month');
        $showOptimizations = $input->getOption('optimizations');

        // Check if codebase exists
        if (!is_dir($codebasePath)) {
            $io->error("Codebase directory not found: $codebasePath");
            return Command::FAILURE;
        }

        // Initialize cost estimator
        $costEstimator = new CostEstimator(
            array_merge($this->config, ['claude_model' => $model]),
            $this->logger
        );

        // Show current month usage if requested
        if ($showCurrentMonth) {
            $this->showCurrentMonthUsage($io, $storageDir);
            return Command::SUCCESS;
        }

        // Show optimizations if requested
        if ($showOptimizations) {
            $this->showOptimizations($io, $storageDir);
            return Command::SUCCESS;
        }

        // Estimate initial ingestion cost
        $io->section('Initial Codebase Ingestion Cost Estimate');

        try {
            $ingestionCost = $costEstimator->estimateIngestionCost($codebasePath);

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Codebase Size', $ingestionCost['codebase_size'] . ' bytes'],
                    ['Estimated Files', number_format($ingestionCost['estimated_files'])],
                    ['Input Tokens', number_format($ingestionCost['input_tokens'])],
                    ['Output Tokens', number_format($ingestionCost['output_tokens'])],
                    ['Total Tokens', number_format($ingestionCost['total_tokens'])],
                    ['Estimated Cost', '$' . number_format($ingestionCost['estimated_cost'], 2)]
                ]
            );

            if (isset($ingestionCost['optimized_cost'])) {
                $savings = $ingestionCost['estimated_cost'] - $ingestionCost['optimized_cost'];
                $savingsPercent = ($savings / $ingestionCost['estimated_cost']) * 100;

                $io->success(sprintf(
                    'With semantic chunking, cost reduced to $%.2f (saving $%.2f, %.1f%%)',
                    $ingestionCost['optimized_cost'],
                    $savings,
                    $savingsPercent
                ));
            }
        } catch (\Exception $e) {
            $io->error("Failed to estimate ingestion cost: {$e->getMessage()}");
        }

        // Estimate daily operation costs
        $io->section('Daily Operation Cost Estimate');

        try {
            $dailyCost = $costEstimator->estimateDailyOperationCost(
                $teamSize,
                $changesPerDay
            );

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Team Size', $teamSize . ' developers'],
                    ['Changes Per Day', $changesPerDay . ' files'],
                    ['Daily Tokens', number_format($dailyCost['daily_tokens'])],
                    ['Daily Cost', '$' . number_format($dailyCost['daily_cost'], 2)],
                    ['Monthly Cost (21 work days)', '$' . number_format($dailyCost['monthly_cost'], 2)],
                    ['Cost Per Developer', '$' . number_format($dailyCost['cost_per_developer'], 2) . '/month']
                ]
            );

            if (isset($dailyCost['optimized_monthly_cost'])) {
                $savings = $dailyCost['monthly_cost'] - $dailyCost['optimized_monthly_cost'];
                $savingsPercent = ($savings / $dailyCost['monthly_cost']) * 100;

                $io->success(sprintf(
                    'With optimizations, monthly cost reduced to $%.2f (saving $%.2f, %.1f%%)',
                    $dailyCost['optimized_monthly_cost'],
                    $savings,
                    $savingsPercent
                ));
            }
        } catch (\Exception $e) {
            $io->error("Failed to estimate operation cost: {$e->getMessage()}");
        }

        // Provide business value estimate
        $io->section('Business Value Estimate');

        $avgEngineerHourRate = 50; // $50/hour
        $engineerHoursPerMonth = $teamSize * 160; // 160 hours per month per engineer

        $bugResolutionSavings = ($teamSize * 2 * 4 * $avgEngineerHourRate); // 2 bugs per engineer, 4 hours saved each
        $onboardingSavings = 32 * $avgEngineerHourRate; // 32 hours saved per onboarding
        $codeReviewSavings = ($teamSize * 10 * $avgEngineerHourRate); // 10 hours saved per engineer on code reviews

        $totalMonthlySavings = $bugResolutionSavings + $codeReviewSavings;
        $roi = ($totalMonthlySavings / $dailyCost['monthly_cost']) * 100;

        $io->table(
            ['Metric', 'Value'],
            [
                ['Engineer Hours Monthly', $engineerHoursPerMonth . ' hours'],
                ['Bug Resolution Savings', '$' . number_format($bugResolutionSavings, 2) . '/month'],
                ['Code Review Savings', '$' . number_format($codeReviewSavings, 2) . '/month'],
                ['New Hire Onboarding Savings', '$' . number_format($onboardingSavings, 2) . '/hire'],
                ['Total Monthly Savings', '$' . number_format($totalMonthlySavings, 2)],
                ['Return on Investment', number_format($roi, 1) . '%']
            ]
        );

        $io->success([
            "Budget estimates completed successfully!",
            sprintf(
                "Initial ingestion: $%.2f, Monthly operation: $%.2f",
                $ingestionCost['optimized_cost'] ?? $ingestionCost['estimated_cost'],
                $dailyCost['optimized_monthly_cost'] ?? $dailyCost['monthly_cost']
            ),
            sprintf("Estimated ROI: %.1f%%", $roi)
        ]);

        return Command::SUCCESS;
    }

    /**
     * Show current month usage
     */
    private function showCurrentMonthUsage(SymfonyStyle $io, string $storageDir): void
    {
        $metrics = new UsageMetrics($storageDir, $this->logger);

        // Get current month dates
        $startOfMonth = date('Y-m-01');
        $today = date('Y-m-d');

        $io->section('Current Month Usage (' . $startOfMonth . ' to ' . $today . ')');

        $totalTokens = 0;
        $totalCost = 0;
        $totalPrompts = 0;
        $dailyReports = [];

        // Get daily reports for the month
        $currentDate = new \DateTime($startOfMonth);
        $endDate = new \DateTime($today);

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $report = $metrics->generateDailyReport($dateStr);

            if ($report['total_prompts'] > 0) {
                $dailyReports[$dateStr] = $report;
                $totalTokens += $report['total_tokens'];
                $totalCost += $report['total_cost'];
                $totalPrompts += $report['total_prompts'];
            }

            $currentDate->modify('+1 day');
        }

        if (empty($dailyReports)) {
            $io->warning('No usage data found for the current month.');
            return;
        }

        // Display summary
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Prompts', number_format($totalPrompts)],
                ['Total Tokens', number_format($totalTokens)],
                ['Total Cost', '$' . number_format($totalCost, 2)],
                ['Average Cost Per Day', '$' . number_format($totalCost / count($dailyReports), 2)]
            ]
        );

        // Display daily breakdown
        $tableRows = [];
        foreach ($dailyReports as $date => $report) {
            $tableRows[] = [
                $date,
                number_format($report['total_prompts']),
                number_format($report['total_tokens']),
                '$' . number_format($report['total_cost'], 2)
            ];
        }

        $io->section('Daily Breakdown');
        $io->table(
            ['Date', 'Prompts', 'Tokens', 'Cost'],
            $tableRows
        );

        // Display prompt type breakdown
        $promptTypes = [];
        foreach ($dailyReports as $report) {
            foreach ($report['prompt_types'] ?? [] as $type => $count) {
                if (!isset($promptTypes[$type])) {
                    $promptTypes[$type] = 0;
                }
                $promptTypes[$type] += $count;
            }
        }

        arsort($promptTypes);

        $promptTypeRows = [];
        foreach ($promptTypes as $type => $count) {
            $percentage = ($count / $totalPrompts) * 100;
            $promptTypeRows[] = [
                $type,
                number_format($count),
                number_format($percentage, 1) . '%'
            ];
        }

        $io->section('Prompt Types');
        $io->table(
            ['Type', 'Count', 'Percentage'],
            $promptTypeRows
        );
    }

    /**
     * Show optimization recommendations
     */
    private function showOptimizations(SymfonyStyle $io, string $storageDir): void
    {
        $metrics = new UsageMetrics($storageDir, $this->logger);

        $io->section('Cost Optimization Recommendations');

        $analysis = $metrics->analyzeUsagePatterns();

        if ($analysis['status'] === 'error') {
            $io->warning($analysis['message']);
            return;
        }

        $io->text([
            'Analysis based on:',
            '- ' . number_format($analysis['analyzed_prompts']) . ' prompts',
            '- ' . number_format($analysis['analyzed_tokens']) . ' tokens',
            '- Date range: ' . $analysis['date_range']['from'] . ' to ' . $analysis['date_range']['to'],
        ]);

        if (empty($analysis['recommendations'])) {
            $io->success('No optimization recommendations found - your usage appears efficient!');
            return;
        }

        $tableRows = [];
        foreach ($analysis['recommendations'] as $rec) {
            $tableRows[] = [
                $rec['type'],
                $rec['severity'],
                $rec['description'],
                $rec['estimated_savings']
            ];
        }

        $io->table(
            ['Type', 'Severity', 'Description', 'Est. Savings'],
            $tableRows
        );

        // Show total potential savings
        $potentialSavings = array_reduce(
            $analysis['recommendations'],
            function ($carry, $item) {
                $savingsRange = explode('-', $item['estimated_savings']);
                $maxSavings = (float) rtrim(end($savingsRange), '%') / 100;
                return $carry + $maxSavings;
            },
            0
        );

        $io->success(sprintf(
            'Implementing all recommendations could save up to %.1f%% on your Claude usage costs!',
            $potentialSavings * 100
        ));
    }
}