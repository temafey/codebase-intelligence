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
use CodebaseIntelligence\Analysis\CodebaseAnalyzer;
use CodebaseIntelligence\Cache\ResponseCache;
use Psr\Log\LoggerInterface;

/**
 * Command for analyzing codebase with Claude
 */
#[AsCommand(name: 'analyze')]
class AnalyzeCommand extends Command
{
    protected static $defaultDescription = 'Analyze your codebase with Claude';

    private LoggerInterface $logger;
    private AIModelClientInterface $client;
    private array $config;
    private ?SessionManager $sessionManager = null;
    private ?ResponseCache $cache = null;

    public function __construct(
        AIModelClientInterface $client,
        array $config,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct();
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger ?? new Logger('analyze-command');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument(
                'codebase-path',
                InputArgument::OPTIONAL,
                'Path to the codebase you want to analyze',
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
                'prompt',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Specific question or prompt for Claude',
                null
            )
            ->addOption(
                'session-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'Use an existing session',
                null
            )
            ->addOption(
                'thinking-mode',
                't',
                InputOption::VALUE_OPTIONAL,
                'Thinking mode (default or extended)',
                'default'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file for analysis results',
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
        $io->title('Analyzing Codebase with Claude');

        $codebasePath = $input->getArgument('codebase-path');
        $storageDir = $input->getOption('storage-dir');
        $prompt = $input->getOption('prompt');
        $sessionId = $input->getOption('session-id');
        $thinkingMode = $input->getOption('thinking-mode');
        $outputFile = $input->getOption('output');
        $noCache = $input->getOption('no-cache');

        // Initialize components
        $this->sessionManager = new SessionManager(
            $this->client,
            $storageDir,
            $this->config,
            $this->logger
        );

        $this->cache = new ResponseCache(
            array_merge($this->config, ['cache_enabled' => !$noCache]),
            $this->logger
        );

        // Check if codebase exists
        if (!is_dir($codebasePath)) {
            $io->error("Codebase directory not found: $codebasePath");
            return Command::FAILURE;
        }

        // Create or resume session
        if ($sessionId) {
            try {
                $session = $this->sessionManager->resumeSession($sessionId);
                $io->success("Resumed session: $sessionId");
            } catch (\Exception $e) {
                $io->error("Failed to resume session: {$e->getMessage()}");
                return Command::FAILURE;
            }
        } else {
            try {
                $session = $this->sessionManager->createSession('codebase-analysis-' . date('YmdHis'));
                $sessionId = $session['id'];
                $io->success("Created new session: $sessionId");
            } catch (\Exception $e) {
                $io->error("Failed to create session: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        // Analyze codebase structure
        $io->section('Analyzing Codebase Structure');
        $io->text('This might take a few minutes depending on the size of your codebase...');

        $analyzer = new CodebaseAnalyzer($codebasePath, $this->config, null, $this->logger);
        $analysis = $analyzer->analyze();

        $io->info(sprintf(
            'Found %d files in %d directories, with %d core components',
            $analysis['structure']['files'] ?? 0,
            $analysis['structure']['directories'] ?? 0,
            count($analysis['core_components']['core_modules'] ?? [])
        ));

        // Send analysis to Claude
        $io->section('Sending Analysis to Claude');

        // Prepare context for Claude
        $analysisSummary = json_encode($analysis, JSON_PRETTY_PRINT);

        // Default prompt if none provided
        if (empty($prompt)) {
            $prompt = "Please analyze this codebase structure and provide insights on the architecture, potential improvements, and best practices. Focus on identifying any architectural issues, potential bottlenecks, and suggesting improvements.";
        }

        $contextPrompt = <<<EOT
I'm sending you the analysis of a codebase. This includes the structure, dependencies, complexity metrics, and core components.

Analysis data:
$analysisSummary

Based on this analysis, please respond to the following prompt:
$prompt
EOT;

        try {
            $progress = $io->createProgressBar(3);
            $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');

            $progress->setMessage('Sending analysis to Claude...');
            $progress->start();

            // First check cache
            $codeContext = ['codebase_path' => $codebasePath];
            $cachedResponse = $this->cache->getCachedResponse($contextPrompt, $codeContext);

            if ($cachedResponse) {
                $progress->setMessage('Retrieved from cache');
                $progress->finish();
                $io->newLine(2);

                $responseContent = $cachedResponse['content'] ?? 'No response content found in cache';
                $io->text($responseContent);

                // Save to file if requested
                if ($outputFile) {
                    file_put_contents($outputFile, $responseContent);
                    $io->success("Analysis saved to: $outputFile");
                }

                return Command::SUCCESS;
            }

            $progress->setMessage('Processing analysis...');
            $progress->advance();

            // Use extended thinking for complex codebases
            $thinkingModeArg = ($thinkingMode === 'extended') ? 'extended_thinking' : 'default';
            $response = $this->client->sendPrompt($sessionId, $contextPrompt, $thinkingModeArg);

            $progress->setMessage('Analysis complete!');
            $progress->finish();
            $io->newLine(2);

            // Cache the response
            $this->cache->cacheResponse($contextPrompt, $codeContext, $response);

            // Display response
            $responseContent = $response['content'] ?? 'No response received from Claude';
            $io->text($responseContent);

            // Save to file if requested
            if ($outputFile) {
                file_put_contents($outputFile, $responseContent);
                $io->success("Analysis saved to: $outputFile");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error during analysis: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}