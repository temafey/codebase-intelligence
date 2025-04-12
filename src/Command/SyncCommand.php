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
use CodebaseIntelligence\API\AIModelClientInterface;
use CodebaseIntelligence\Session\SessionManager;
use CodebaseIntelligence\Analysis\DifferentialUpdater;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Command for synchronizing Claude with codebase changes on a schedule
 */
#[AsCommand(name: 'sync')]
class SyncCommand extends Command
{
    protected static $defaultDescription = 'Synchronize Claude with codebase changes on a schedule';

    private LoggerInterface $logger;
    private AIModelClientInterface $client;
    private array $config;
    private ?SessionManager $sessionManager = null;

    public function __construct(
        AIModelClientInterface $client,
        array                  $config,
        ?LoggerInterface       $logger = null
    )
    {
        parent::__construct();
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger ?? new Logger('sync-command');
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
                'Directory for storing session data',
                $this->config['storage_dir'] ?? getcwd() . '/.claude'
            )
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_REQUIRED,
                'Synchronization mode (daily or weekly)',
                'daily'
            )
            ->addOption(
                'session-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Session ID to update (auto-selected if not specified)',
                null
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force synchronization even if no changes detected'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synchronizing Claude with Codebase');

        $codebasePath = $input->getArgument('codebase-path');
        $storageDir = $input->getOption('storage-dir');
        $mode = $input->getOption('mode');
        $sessionId = $input->getOption('session-id');
        $force = $input->getOption('force');

        // Validate inputs
        if (!in_array($mode, ['daily', 'weekly'])) {
            $io->error("Invalid mode: $mode. Must be 'daily' or 'weekly'");
            return Command::FAILURE;
        }

        // Initialize session manager
        $this->sessionManager = new SessionManager(
            $this->client,
            $storageDir,
            $this->config,
            $this->logger
        );

        // Check if codebase exists
        if (!is_dir($codebasePath)) {
            $io->error("Codebase directory not found: $codebasePath");
            return Command::FAILURE;
        }

        $io->section("Running $mode synchronization");

        // For daily sync, check if we're in off-peak hours unless forced
        if ($mode === 'daily' && !$force) {
            if (!$this->sessionManager->isOffPeakHours()) {
                $io->warning("Skipping daily sync as it's not off-peak hours (2am-8am)");
                $io->text("This helps optimize costs. Use --force to override.");
                return Command::SUCCESS;
            }
        }

        // Create differential updater
        $updater = new DifferentialUpdater($codebasePath, $storageDir, $this->logger);

        // Find changes
        $io->section('Finding Changes Since Last Sync');
        try {
            $changedFiles = $updater->findChanges();

            if (empty($changedFiles) && !$force) {
                $io->success('No changes detected since last sync. Nothing to do!');
                return Command::SUCCESS;
            }

            if (empty($changedFiles) && $force) {
                $io->note('No changes detected, but continuing due to --force option');
            } else {
                $io->success(sprintf('Found %d changed files', count($changedFiles)));
            }
        } catch (\Exception $e) {
            $io->error("Failed to find changes: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Find or create session
        if ($sessionId) {
            try {
                $session = $this->sessionManager->resumeSession($sessionId);
                $io->success("Resumed session: $sessionId");
            } catch (\Exception $e) {
                $io->error("Failed to resume session: {$e->getMessage()}");
                return Command::FAILURE;
            }
        } else {
            // Try to find the latest session
            try {
                $sessions = $this->sessionManager->listSessions(5);

                if (!empty($sessions)) {
                    $latestSession = $sessions[0];
                    $sessionId = $latestSession['id'];
                    $session = $this->sessionManager->resumeSession($sessionId);
                    $io->success("Resumed latest session: $sessionId");
                } else {
                    $session = $this->sessionManager->createSession('codebase-sync-' . date('YmdHis'));
                    $sessionId = $session['id'];
                    $io->success("Created new session (no existing sessions found): $sessionId");
                }
            } catch (\Exception $e) {
                $io->error("Failed to find or create session: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        if (!empty($changedFiles) || $force) {
            // Generate diffs and update package
            $io->section('Generating Update Package');
            try {
                $updatePackage = $updater->generateUpdatePackage();
                $io->success("Generated update package: " . basename($updatePackage));

                // Send update to Claude
                $io->section('Sending Update to Claude');
                $this->client->sendFile(
                    $sessionId,
                    $updatePackage,
                    "These are the changes to the codebase for the $mode sync"
                );

                $io->success("Sent update package to Claude");
            } catch (\Exception $e) {
                $io->error("Failed to generate or send update: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        // Final prompt
        $io->section('Finalizing Sync');
        $syncType = ucfirst($mode);
        $finalPrompt = <<<EOT
I'm performing a $syncType synchronization of our codebase with you.

Please:
1. Update your understanding of the codebase with any changes sent
2. Confirm you have the latest information about our project
3. Note any significant updates you've observed
4. Mention any patterns or trends you're seeing in our development

This regular sync helps ensure you always have the most current understanding of our codebase.
EOT;

        try {
            $response = $this->client->sendPrompt($sessionId, $finalPrompt);
            $io->text($response['content'] ?? 'No response from Claude');

            // Save response
            $syncSummaryFile = $storageDir . '/docs/sync_summary_' . $mode . '_' . date('YmdHis') . '.md';
            $filesystem = new Filesystem();
            $filesystem->mkdir(dirname($syncSummaryFile));
            file_put_contents($syncSummaryFile, $response['content'] ?? '');

            $io->success([
                "Codebase $mode sync completed successfully!",
                "Session ID: $sessionId",
                "Summary saved to: " . basename($syncSummaryFile)
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to finalize sync: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}