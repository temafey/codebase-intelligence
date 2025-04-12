<?php


declare(strict_types=1);

namespace CodebaseIntelligence\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use CodebaseIntelligence\Utils\UsageMetrics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Command for generating usage metrics reports
 */
#[AsCommand(name: 'metrics')]
class MetricsCommand extends Command
{
    protected static $defaultDescription = 'Generate usage metrics reports';

    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        parent::__construct();
        $this->config = $config;
        $this->logger = $logger ?? new Logger('metrics-command');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption(
                'storage-dir',
                's',
                InputOption::VALUE_OPTIONAL,
                'Directory for storage',
                $this->config['storage_dir'] ?? getcwd() . '/.claude'
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_OPTIONAL,
                'Report type (daily, weekly, monthly, full)',
                'daily'
            )
            ->addOption(
                'date',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Report date (YYYY-MM-DD for daily, YYYY-MM for monthly)',
                date('Y-m-d')
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format (console, json, csv, markdown)',
                'console'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file (if not using console format)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Usage Metrics Report');

        $storageDir = $input->getOption('storage-dir');
        $type = $input->getOption('type');
        $date = $input->getOption('date');
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');

        if (!is_dir($storageDir)) {
            $io->error("Storage directory not found: $storageDir");
            return Command::FAILURE;
        }

        $metrics = new UsageMetrics($storageDir, $this->logger);

        switch ($type) {
            case 'daily':
                $report = $metrics->generateDailyReport($date);
                $title = "Daily Report for $date";
                break;

            case 'weekly':
                $report = $this->generateWeeklyReport($metrics, $date);
                $title = "Weekly Report for week of " . $report['start_date'] . " to " . $report['end_date'];
                break;

            case 'monthly':
                $report = $this->generateMonthlyReport($metrics, $date);
                $title = "Monthly Report for " . $report['month'];
                break;

            case 'full':
                $report = $this->generateFullReport($metrics);
                $title = "Full Usage Report";
                break;

            default:
                $io->error("Invalid report type: $type");
                return Command::FAILURE;
        }

        if ($format === 'console') {
            $this->displayConsoleReport($io, $report, $title);
        } else {
            $formattedReport = $this->formatReport($report, $format);

            if ($outputFile) {
                file_put_contents($outputFile, $formattedReport);
                $io->success("Report saved to: $outputFile");
            } else {
                $output->write($formattedReport);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Generate weekly report
     */
    private function generateWeeklyReport(UsageMetrics $metrics, string $date): array
    {
        // Determine start and end of week
        $dateObj = new \DateTime($date);
        $dayOfWeek = $dateObj->format('N');

        // Adjust to start of week (Monday)
        $startDate = clone $dateObj;
        $startDate->modify('-' . ($dayOfWeek - 1) . ' days');

        // Adjust to end of week (Sunday)
        $endDate = clone $startDate;
        $endDate->modify('+6 days');

        // Ensure we don't go beyond today
        $today = new \DateTime();
        if ($endDate > $today) {
            $endDate = $today;
        }

        // Generate report for each day in the week
        $dailyReports = [];
        $currentDate = clone $startDate;

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $dailyReport = $metrics->generateDailyReport($dateStr);

            if ($dailyReport['total_prompts'] > 0) {
                $dailyReports[$dateStr] = $dailyReport;
            }

            $currentDate->modify('+1 day');
        }

        // Aggregate data
        $totalPrompts = 0;
        $totalTokens = 0;
        $totalCost = 0;
        $promptTypes = [];

        foreach ($dailyReports as $report) {
            $totalPrompts += $report['total_prompts'];
            $totalTokens += $report['total_tokens'];
            $totalCost += $report['total_cost'];

            // Aggregate prompt types
            foreach ($report['prompt_types'] ?? [] as $type => $count) {
                if (!isset($promptTypes[$type])) {
                    $promptTypes[$type] = 0;
                }
                $promptTypes[$type] += $count;
            }
        }

        return [
            'type' => 'weekly',
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'total_prompts' => $totalPrompts,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
            'daily_reports' => $dailyReports,
            'prompt_types' => $promptTypes
        ];
    }

    /**
     * Generate monthly report
     */
    private function generateMonthlyReport(UsageMetrics $metrics, string $date): array
    {
        // Parse month and year
        if (preg_match('/^(\d{4})-(\d{2})$/', $date, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
        } else {
            $dateObj = new \DateTime($date);
            $year = (int)$dateObj->format('Y');
            $month = (int)$dateObj->format('m');
        }

        // Determine start and end of month
        $startDate = new \DateTime("$year-$month-01");
        $endDate = clone $startDate;
        $endDate->modify('last day of this month');

        // Ensure we don't go beyond today
        $today = new \DateTime();
        if ($endDate > $today) {
            $endDate = $today;
        }

        // Generate report for each day in the month
        $dailyReports = [];
        $currentDate = clone $startDate;

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $dailyReport = $metrics->generateDailyReport($dateStr);

            if ($dailyReport['total_prompts'] > 0) {
                $dailyReports[$dateStr] = $dailyReport;
            }

            $currentDate->modify('+1 day');
        }

        // Aggregate data
        $totalPrompts = 0;
        $totalTokens = 0;
        $totalCost = 0;
        $promptTypes = [];
        $dailyTokens = [];

        foreach ($dailyReports as $date => $report) {
            $totalPrompts += $report['total_prompts'];
            $totalTokens += $report['total_tokens'];
            $totalCost += $report['total_cost'];
            $dailyTokens[$date] = $report['total_tokens'];

            // Aggregate prompt types
            foreach ($report['prompt_types'] ?? [] as $type => $count) {
                if (!isset($promptTypes[$type])) {
                    $promptTypes[$type] = 0;
                }
                $promptTypes[$type] += $count;
            }
        }

        return [
            'type' => 'monthly',
            'month' => date('F Y', strtotime("$year-$month-01")),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'total_prompts' => $totalPrompts,
            'total_tokens' => $totalTokens,
            'total_cost' => $totalCost,
            'daily_tokens' => $dailyTokens,
            'daily_reports' => $dailyReports,
            'prompt_types' => $promptTypes
        ];
    }

    /**
     * Generate full usage report
     */
    private function generateFullReport(UsageMetrics $metrics): array
    {
        $analysis = $metrics->analyzeUsagePatterns();

        if ($analysis['status'] === 'error') {
            return [
                'type' => 'full',
                'error' => $analysis['message']
            ];
        }

        // Get monthly data
        $monthlyCosts = [];
        $monthlyTokens = [];

        // Start date is first of current month, 6 months ago
        $startDate = new \DateTime('first day of 6 months ago');
        $endDate = new \DateTime('last day of this month');
        $today = new \DateTime();

        if ($endDate > $today) {
            $endDate = $today;
        }

        $currentDate = clone $startDate;
        while ($currentDate->format('Y-m') <= $endDate->format('Y-m')) {
            $year = $currentDate->format('Y');
            $month = $currentDate->format('m');

            $monthlyReport = $this->generateMonthlyReport($metrics, "$year-$month");

            $monthlyCosts[$currentDate->format('Y-m')] = $monthlyReport['total_cost'];
            $monthlyTokens[$currentDate->format('Y-m')] = $monthlyReport['total_tokens'];

            $currentDate->modify('first day of next month');
        }

        return [
            'type' => 'full',
            'analyzed_prompts' => $analysis['analyzed_prompts'],
            'analyzed_tokens' => $analysis['analyzed_tokens'],
            'date_range' => $analysis['date_range'],
            'recommendations' => $analysis['recommendations'],
            'monthly_costs' => $monthlyCosts,
            'monthly_tokens' => $monthlyTokens,
            'prompt_types' => $analysis['prompt_types'] ?? [],
        ];
    }

    /**
     * Display report in console format
     */
    private function displayConsoleReport(SymfonyStyle $io, array $report, string $title): void
    {
        $io->section($title);

        switch ($report['type']) {
            case 'daily':
                $this->displayDailyReport($io, $report);
                break;

            case 'weekly':
                $this->displayWeeklyReport($io, $report);
                break;

            case 'monthly':
                $this->displayMonthlyReport($io, $report);
                break;

            case 'full':
                $this->displayFullReport($io, $report);
                break;
        }
    }

    /**
     * Display daily report
     */
    private function displayDailyReport(SymfonyStyle $io, array $report): void
    {
        if (isset($report['error'])) {
            $io->warning($report['error']);
            return;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['Date', $report['date']],
                ['Total Prompts', number_format($report['total_prompts'])],
                ['Total Tokens', number_format($report['total_tokens'])],
                ['Total Cost', '$' . number_format($report['total_cost'], 2)],
                ['Avg Tokens/Prompt', number_format($report['average_tokens_per_prompt'], 1)]
            ]
        );

        if (!empty($report['prompt_types'])) {
            $io->section('Prompt Types');

            $rows = [];
            foreach ($report['prompt_types'] as $type => $count) {
                $percentage = ($count / $report['total_prompts']) * 100;
                $rows[] = [
                    $type,
                    number_format($count),
                    number_format($percentage, 1) . '%'
                ];
            }

            $io->table(['Type', 'Count', 'Percentage'], $rows);
        }

        if (!empty($report['hourly_usage'])) {
            $io->section('Hourly Usage');

            $hourlyRows = [];
            for ($hour = 0; $hour < 24; $hour++) {
                if ($report['hourly_usage'][$hour] > 0) {
                    $percentage = ($report['hourly_usage'][$hour] / $report['total_tokens']) * 100;
                    $hourlyRows[] = [
                        sprintf('%02d:00 - %02d:59', $hour, $hour),
                        number_format($report['hourly_usage'][$hour]),
                        number_format($percentage, 1) . '%'
                    ];
                }
            }

            $io->table(['Hour', 'Tokens', 'Percentage'], $hourlyRows);
        }
    }

    /**
     * Display weekly report
     */
    private function displayWeeklyReport(SymfonyStyle $io, array $report): void
    {
        $io->table(
            ['Metric', 'Value'],
            [
                ['Period', $report['start_date'] . ' to ' . $report['end_date']],
                ['Total Prompts', number_format($report['total_prompts'])],
                ['Total Tokens', number_format($report['total_tokens'])],
                ['Total Cost', '$' . number_format($report['total_cost'], 2)],
                ['Avg Cost/Day', '$' . number_format($report['total_cost'] / count($report['daily_reports']), 2)]
            ]
        );

        // Daily breakdown
        $io->section('Daily Breakdown');

        $dailyRows = [];
        foreach ($report['daily_reports'] as $date => $dailyReport) {
            $dailyRows[] = [
                $date,
                number_format($dailyReport['total_prompts']),
                number_format($dailyReport['total_tokens']),
                '$' . number_format($dailyReport['total_cost'], 2)
            ];
        }

        $io->table(['Date', 'Prompts', 'Tokens', 'Cost'], $dailyRows);

        // Prompt types
        if (!empty($report['prompt_types'])) {
            $io->section('Prompt Types');

            $rows = [];
            foreach ($report['prompt_types'] as $type => $count) {
                $percentage = ($count / $report['total_prompts']) * 100;
                $rows[] = [
                    $type,
                    number_format($count),
                    number_format($percentage, 1) . '%'
                ];
            }

            $io->table(['Type', 'Count', 'Percentage'], $rows);
        }
    }

    /**
     * Display monthly report
     */
    private function displayMonthlyReport(SymfonyStyle $io, array $report): void
    {
        $io->table(
            ['Metric', 'Value'],
            [
                ['Month', $report['month']],
                ['Total Prompts', number_format($report['total_prompts'])],
                ['Total Tokens', number_format($report['total_tokens'])],
                ['Total Cost', '$' . number_format($report['total_cost'], 2)],
                ['Active Days', count($report['daily_reports'])],
                ['Avg Cost/Day', '$' . number_format($report['total_cost'] / max(1, count($report['daily_reports'])), 2)]
            ]
        );

        // Daily breakdown (top 10 days)
        $io->section('Top 10 Days by Usage');

        // Sort days by token usage
        $sortedDays = $report['daily_tokens'];
        arsort($sortedDays);
        $topDays = array_slice($sortedDays, 0, 10, true);

        $dailyRows = [];
        foreach ($topDays as $date => $tokens) {
            $dailyReport = $report['daily_reports'][$date];
            $dailyRows[] = [
                $date,
                number_format($dailyReport['total_prompts']),
                number_format($tokens),
                '$' . number_format($dailyReport['total_cost'], 2)
            ];
        }

        $io->table(['Date', 'Prompts', 'Tokens', 'Cost'], $dailyRows);

        // Prompt types
        if (!empty($report['prompt_types'])) {
            $io->section('Prompt Types');

            // Sort prompt types by count
            $promptTypes = $report['prompt_types'];
            arsort($promptTypes);

            $rows = [];
            foreach ($promptTypes as $type => $count) {
                $percentage = ($count / $report['total_prompts']) * 100;
                $rows[] = [
                    $type,
                    number_format($count),
                    number_format($percentage, 1) . '%'
                ];
            }

            $io->table(['Type', 'Count', 'Percentage'], $rows);
        }
    }

    /**
     * Display full report
     */
    private function displayFullReport(SymfonyStyle $io, array $report): void
    {
        if (isset($report['error'])) {
            $io->warning($report['error']);
            return;
        }

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Analyzed Prompts', number_format($report['analyzed_prompts'])],
                ['Total Analyzed Tokens', number_format($report['analyzed_tokens'])],
                ['Date Range', $report['date_range']['from'] . ' to ' . $report['date_range']['to']]
            ]
        );

        // Monthly breakdown
        $io->section('Monthly Usage');

        $monthlyRows = [];
        foreach ($report['monthly_costs'] as $month => $cost) {
            $monthlyRows[] = [
                date('F Y', strtotime($month . '-01')),
                number_format($report['monthly_tokens'][$month]),
                '$' . number_format($cost, 2)
            ];
        }

        $io->table(['Month', 'Tokens', 'Cost'], $monthlyRows);

        // Recommendations
        if (!empty($report['recommendations'])) {
            $io->section('Cost Optimization Recommendations');

            $recRows = [];
            foreach ($report['recommendations'] as $rec) {
                $recRows[] = [
                    $rec['type'],
                    $rec['severity'],
                    $rec['description'],
                    $rec['estimated_savings']
                ];
            }

            $io->table(['Type', 'Severity', 'Description', 'Est. Savings'], $recRows);
        } else {
            $io->success('No cost optimization recommendations - your usage is already efficient!');
        }

        // Prompt types
        if (!empty($report['prompt_types'])) {
            $io->section('Prompt Types');

            // Sort prompt types by count
            $promptTypes = $report['prompt_types'];
            arsort($promptTypes);

            $rows = [];
            foreach (array_slice($promptTypes, 0, 10) as $type => $count) {
                $percentage = ($count / $report['analyzed_prompts']) * 100;
                $rows[] = [
                    $type,
                    number_format($count),
                    number_format($percentage, 1) . '%'
                ];
            }

            $io->table(['Type (Top 10)', 'Count', 'Percentage'], $rows);
        }
    }

    /**
     * Format report for output
     */
    private function formatReport(array $report, string $format): string
    {
        switch ($format) {
            case 'json':
                return json_encode($report, JSON_PRETTY_PRINT);

            case 'csv':
                return $this->formatCsv($report);

            case 'markdown':
                return $this->formatMarkdown($report);

            default:
                return json_encode($report, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Format report as CSV
     */
    private function formatCsv(array $report): string
    {
        $output = '';

        // Function to add a CSV row
        $addRow = function ($row) {
            return implode(',', array_map(function ($cell) {
                    return '"' . str_replace('"', '""', $cell) . '"';
                }, $row)) . "\n";
        };

        switch ($report['type']) {
            case 'daily':
                $output .= $addRow(['Date', 'Prompts', 'Tokens', 'Cost']);
                $output .= $addRow([
                    $report['date'],
                    $report['total_prompts'],
                    $report['total_tokens'],
                    $report['total_cost']
                ]);

                // Add prompt types
                $output .= "\n" . $addRow(['Prompt Type', 'Count', 'Percentage']);
                foreach ($report['prompt_types'] ?? [] as $type => $count) {
                    $percentage = ($count / $report['total_prompts']) * 100;
                    $output .= $addRow([
                        $type,
                        $count,
                        number_format($percentage, 1) . '%'
                    ]);
                }
                break;

            case 'weekly':
            case 'monthly':
                $output .= $addRow(['Date', 'Prompts', 'Tokens', 'Cost']);
                foreach ($report['daily_reports'] as $date => $dailyReport) {
                    $output .= $addRow([
                        $date,
                        $dailyReport['total_prompts'],
                        $dailyReport['total_tokens'],
                        $dailyReport['total_cost']
                    ]);
                }

                // Add summary
                $output .= "\n" . $addRow(['Summary', '', '', '']);
                $output .= $addRow([
                    'Total',
                    $report['total_prompts'],
                    $report['total_tokens'],
                    $report['total_cost']
                ]);
                break;

            case 'full':
                $output .= $addRow(['Month', 'Tokens', 'Cost']);
                foreach ($report['monthly_costs'] as $month => $cost) {
                    $output .= $addRow([
                        date('F Y', strtotime($month . '-01')),
                        $report['monthly_tokens'][$month],
                        $cost
                    ]);
                }

                // Add recommendations
                if (!empty($report['recommendations'])) {
                    $output .= "\n" . $addRow(['Recommendation Type', 'Severity', 'Description', 'Est. Savings']);
                    foreach ($report['recommendations'] as $rec) {
                        $output .= $addRow([
                            $rec['type'],
                            $rec['severity'],
                            $rec['description'],
                            $rec['estimated_savings']
                        ]);
                    }
                }
                break;
        }

        return $output;
    }

    /**
     * Format report as Markdown
     */
    private function formatMarkdown(array $report): string
    {
        $output = '';

        switch ($report['type']) {
            case 'daily':
                $output .= "# Daily Usage Report: {$report['date']}\n\n";
                $output .= "## Summary\n\n";
                $output .= "- **Total Prompts:** " . number_format($report['total_prompts']) . "\n";
                $output .= "- **Total Tokens:** " . number_format($report['total_tokens']) . "\n";
                $output .= "- **Total Cost:** $" . number_format($report['total_cost'], 2) . "\n";
                $output .= "- **Average Tokens/Prompt:** " . number_format($report['average_tokens_per_prompt'], 1) . "\n\n";

                if (!empty($report['prompt_types'])) {
                    $output .= "## Prompt Types\n\n";
                    $output .= "| Type | Count | Percentage |\n";
                    $output .= "| ---- | ----- | ---------- |\n";

                    foreach ($report['prompt_types'] as $type => $count) {
                        $percentage = ($count / $report['total_prompts']) * 100;
                        $output .= "| $type | " . number_format($count) . " | " . number_format($percentage, 1) . "% |\n";
                    }
                    $output .= "\n";
                }

                if (!empty($report['hourly_usage'])) {
                    $output .= "## Hourly Usage\n\n";
                    $output .= "| Hour | Tokens | Percentage |\n";
                    $output .= "| ---- | ------ | ---------- |\n";

                    for ($hour = 0; $hour < 24; $hour++) {
                        if ($report['hourly_usage'][$hour] > 0) {
                            $percentage = ($report['hourly_usage'][$hour] / $report['total_tokens']) * 100;
                            $hourRange = sprintf('%02d:00 - %02d:59', $hour, $hour);
                            $output .= "| $hourRange | " . number_format($report['hourly_usage'][$hour]) . " | " . number_format($percentage, 1) . "% |\n";
                        }
                    }
                    $output .= "\n";
                }
                break;

            case 'weekly':
                $output .= "# Weekly Usage Report: {$report['start_date']} to {$report['end_date']}\n\n";
                $output .= "## Summary\n\n";
                $output .= "- **Total Prompts:** " . number_format($report['total_prompts']) . "\n";
                $output .= "- **Total Tokens:** " . number_format($report['total_tokens']) . "\n";
                $output .= "- **Total Cost:** $" . number_format($report['total_cost'], 2) . "\n";
                $output .= "- **Average Daily Cost:** $" . number_format($report['total_cost'] / count($report['daily_reports']), 2) . "\n\n";

                $output .= "## Daily Breakdown\n\n";
                $output .= "| Date | Prompts | Tokens | Cost |\n";
                $output .= "| ---- | ------- | ------ | ---- |\n";

                foreach ($report['daily_reports'] as $date => $dailyReport) {
                    $output .= "| $date | " . number_format($dailyReport['total_prompts']) . " | " . number_format($dailyReport['total_tokens']) . " | $" . number_format($dailyReport['total_cost'], 2) . " |\n";
                }
                $output .= "\n";

                if (!empty($report['prompt_types'])) {
                    $output .= "## Prompt Types\n\n";
                    $output .= "| Type | Count | Percentage |\n";
                    $output .= "| ---- | ----- | ---------- |\n";

                    foreach ($report['prompt_types'] as $type => $count) {
                        $percentage = ($count / $report['total_prompts']) * 100;
                        $output .= "| $type | " . number_format($count) . " | " . number_format($percentage, 1) . "% |\n";
                    }
                    $output .= "\n";
                }
                break;

            case 'monthly':
                $output .= "# Monthly Usage Report: {$report['month']}\n\n";
                $output .= "## Summary\n\n";
                $output .= "- **Total Prompts:** " . number_format($report['total_prompts']) . "\n";
                $output .= "- **Total Tokens:** " . number_format($report['total_tokens']) . "\n";
                $output .= "- **Total Cost:** $" . number_format($report['total_cost'], 2) . "\n";
                $output .= "- **Active Days:** " . count($report['daily_reports']) . "\n";
                $output .= "- **Average Daily Cost:** $" . number_format($report['total_cost'] / max(1, count($report['daily_reports'])), 2) . "\n\n";

                // Top 10 days
                $sortedDays = $report['daily_tokens'];
                arsort($sortedDays);
                $topDays = array_slice($sortedDays, 0, 10, true);

                $output .= "## Top 10 Days by Usage\n\n";
                $output .= "| Date | Prompts | Tokens | Cost |\n";
                $output .= "| ---- | ------- | ------ | ---- |\n";

                foreach ($topDays as $date => $tokens) {
                    $dailyReport = $report['daily_reports'][$date];
                    $output .= "| $date | " . number_format($dailyReport['total_prompts']) . " | " . number_format($tokens) . " | $" . number_format($dailyReport['total_cost'], 2) . " |\n";
                }
                $output .= "\n";

                if (!empty($report['prompt_types'])) {
                    $promptTypes = $report['prompt_types'];
                    arsort($promptTypes);

                    $output .= "## Prompt Types\n\n";
                    $output .= "| Type | Count | Percentage |\n";
                    $output .= "| ---- | ----- | ---------- |\n";

                    foreach ($promptTypes as $type => $count) {
                        $percentage = ($count / $report['total_prompts']) * 100;
                        $output .= "| $type | " . number_format($count) . " | " . number_format($percentage, 1) . "% |\n";
                    }
                    $output .= "\n";
                }
                break;

            case 'full':
                if (isset($report['error'])) {
                    $output .= "# Full Usage Report\n\n";
                    $output .= "**Error:** {$report['error']}\n";
                    break;
                }

                $output .= "# Full Usage Report\n\n";
                $output .= "## Summary\n\n";
                $output .= "- **Total Analyzed Prompts:** " . number_format($report['analyzed_prompts']) . "\n";
                $output .= "- **Total Analyzed Tokens:** " . number_format($report['analyzed_tokens']) . "\n";
                $output .= "- **Date Range:** {$report['date_range']['from']} to {$report['date_range']['to']}\n\n";

                $output .= "## Monthly Usage\n\n";
                $output .= "| Month | Tokens | Cost |\n";
                $output .= "| ----- | ------ | ---- |\n";

                foreach ($report['monthly_costs'] as $month => $cost) {
                    $output .= "| " . date('F Y', strtotime($month . '-01')) . " | " . number_format($report['monthly_tokens'][$month]) . " | $" . number_format($cost, 2) . " |\n";
                }
                $output .= "\n";

                if (!empty($report['recommendations'])) {
                    $output .= "## Cost Optimization Recommendations\n\n";
                    $output .= "| Type | Severity | Description | Est. Savings |\n";
                    $output .= "| ---- | -------- | ----------- | ------------ |\n";

                    foreach ($report['recommendations'] as $rec) {
                        $output .= "| {$rec['type']} | {$rec['severity']} | {$rec['description']} | {$rec['estimated_savings']} |\n";
                    }
                    $output .= "\n";
                } else {
                    $output .= "## Cost Optimization\n\n";
                    $output .= "No optimization recommendations - your usage is already efficient!\n\n";
                }

                if (!empty($report['prompt_types'])) {
                    $promptTypes = $report['prompt_types'];
                    arsort($promptTypes);

                    $output .= "## Top 10 Prompt Types\n\n";
                    $output .= "| Type | Count | Percentage |\n";
                    $output .= "| ---- | ----- | ---------- |\n";

                    foreach (array_slice($promptTypes, 0, 10) as $type => $count) {
                        $percentage = ($count / $report['analyzed_prompts']) * 100;
                        $output .= "| $type | " . number_format($count) . " | " . number_format($percentage, 1) . "% |\n";
                    }
                    $output .= "\n";
                }
                break;
        }

        return $output;
    }
}