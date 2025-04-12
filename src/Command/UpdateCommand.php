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
 * Command for updating Claude with recent codebase changes
 */
#[AsCommand(name: 'update')]
class UpdateCommand extends Command
{
    protected static $defaultDescription = 'Update Claude with recent changes to your codebase';

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
        $this->logger = $logger ?? new Logger('update-command');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument(
                'codebase-path',
                InputArgument::OPTIONAL,
                'Path to the codebase you want to update',
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
                'session-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Session ID to update (latest if not specified)',
                null
            )
            ->addOption(
                'schedule',
                null,
                InputOption::VALUE_OPTIONAL,
                'Schedule type (daily, weekly) for recurring updates',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Updating Claude with Codebase Changes');

        $codebasePath = $input->getArgument('codebase-path');
        $storageDir = $input->getOption('storage-dir');
        $sessionId = $input->getOption('session-id');
        $schedule = $input->getOption('schedule');

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

        // Check if this is a scheduled run
        if ($schedule) {
            $io->note("Running as scheduled $schedule update");
            // For scheduled runs, we may want to limit token usage during peak hours
            $offPeakOnly = $schedule === 'daily';

            if ($offPeakOnly && !$this->sessionManager->isOffPeakHours()) {
                $io->warning("Skipping update as it's not off-peak hours (2am-8am)");
                $io->text("This helps optimize costs. You can override with --schedule=none");
                return Command::SUCCESS;
            }
        }

        // Find or create session
        if ($sessionId) {
            try {
                $session = $this->sessionManager->resumeSession($sessionId);
                $io->success("Resumed session: $sessionId");
            } catch (\Exception $e) {
                $io->error("Failed to resume session: {$e->getMessage()}");

                if ($io->confirm('Would you like to create a new session instead?', true)) {
                    try {
                        $session = $this->sessionManager->createSession('codebase-update-' . date('YmdHis'));
                        $sessionId = $session['id'];
                        $io->success("Created new session: $sessionId");
                    } catch (\Exception $e) {
                        $io->error("Failed to create session: {$e->getMessage()}");
                        return Command::FAILURE;
                    }
                } else {
                    return Command::FAILURE;
                }
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
                    $session = $this->sessionManager->createSession('codebase-update-' . date('YmdHis'));
                    $sessionId = $session['id'];
                    $io->success("Created new session (no existing sessions found): $sessionId");
                }
            } catch (\Exception $e) {
                $io->error("Failed to find or create session: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        // Create differential updater
        $updater = new DifferentialUpdater($codebasePath, $storageDir, $this->logger);

        // Find changes
        $io->section('Finding Changes Since Last Update');
        try {
            $changedFiles = $updater->findChanges();

            if (empty($changedFiles)) {
                $io->success('No changes detected since last update!');
                return Command::SUCCESS;
            }

            $io->success(sprintf('Found %d changed files', count($changedFiles)));

            // Show a sample of changed files
            $sampleSize = min(5, count($changedFiles));
            $sample = array_slice($changedFiles, 0, $sampleSize);

            $io->text('Changed files include:');
            foreach ($sample as $file) {
                $relativePath = str_replace($codebasePath . '/', '', $file);
                $io->text("- $relativePath");
            }

            if (count($changedFiles) > $sampleSize) {
                $io->text(sprintf('... and %d more', count($changedFiles) - $sampleSize));
            }
        } catch (\Exception $e) {
            $io->error("Failed to find changes: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Generate diffs and update package
        $io->section('Generating Differential Update');
        try {
            $updatePackage = $updater->generateUpdatePackage();
            $io->success("Generated update package: " . basename($updatePackage));
        } catch (\Exception $e) {
            $io->error("Failed to generate update package: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Send update to Claude
        $io->section('Sending Update to Claude');
        try {
            $this->client->sendFile(
                $sessionId,
                $updatePackage,
                "These are the recent changes to the codebase since the last update"
            );

            $io->success("Sent update package to Claude");
        } catch (\Exception $e) {
            $io->error("Failed to send update: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Final prompt
        $io->section('Finalizing Update');
        $finalPrompt = <<<EOT
I've just sent you a diff file containing the recent changes to our codebase since the last update.

Please:
1. Review these changes and update your understanding of the codebase
2. Note any significant architectural changes
3. Identify any potential issues or improvements in the changes
4. Confirm your updated understanding of the codebase structure

This will ensure you have the most current knowledge of our project for future discussions.
EOT;

        try {
            $response = $this->client->sendPrompt($sessionId, $finalPrompt);
            $io->text($response['content'] ?? 'No response from Claude');

            // Save response
            $updateSummaryFile = $storageDir . '/docs/update_summary_' . date('YmdHis') . '.md';
            $filesystem = new Filesystem();
            $filesystem->mkdir(dirname($updateSummaryFile));
            file_put_contents($updateSummaryFile, $response['content'] ?? '');

            $io->success([
                "Codebase update successfully sent to Claude!",
                "Session ID: $sessionId",
                "Summary saved to: " . basename($updateSummaryFile)
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to finalize update: {$e->getMessage()}");
            $io->warning("Update was sent but not finalized. You can still use the session.");
            return Command::FAILURE;
        }
    }
}