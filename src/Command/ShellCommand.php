<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use CodebaseIntelligence\API\AIModelClientInterface;
use CodebaseIntelligence\Session\SessionManager;
use CodebaseIntelligence\Cache\ResponseCache;
use CodebaseIntelligence\Utils\Logger;
use CodebaseIntelligence\Utils\UsageMetrics;
use Psr\Log\LoggerInterface;

/**
 * Interactive shell for working with Claude
 */
#[AsCommand(name: 'shell')]
class ShellCommand extends Command
{
    protected static $defaultDescription = 'Start an interactive shell session with Claude';

    private LoggerInterface $logger;
    private AIModelClientInterface $client;
    private SessionManager $sessionManager;
    private ResponseCache $cache;
    private UsageMetrics $metrics;
    private array $config;

    public function __construct(
        AIModelClientInterface $client,
        array $config,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct();
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger ?? new Logger('shell-command');

        // These will be initialized in execute() when we have the storage path
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption(
                'session-id',
                's',
                InputOption::VALUE_OPTIONAL,
                'Use an existing session ID instead of creating a new one',
                null
            )
            ->addOption(
                'storage-dir',
                's',
                InputOption::VALUE_OPTIONAL,
                'Directory for storing session data',
                $this->config['storage_dir'] ?? getcwd() . '/.claude'
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
        $io->title('Claude Interactive Shell');

        $sessionId = $input->getOption('session-id');
        $storageDir = $input->getOption('storage-dir') ?? $this->config['storage_dir'] ?? getcwd() . '/.claude';
        $noCache = $input->getOption('no-cache');

        // Initialize components with storage dir
        $this->sessionManager = new SessionManager($this->client, $storageDir, $this->config, $this->logger);
        $this->cache = new ResponseCache(
            array_merge($this->config, ['cache_enabled' => !$noCache]),
            $this->logger
        );
        $this->metrics = new UsageMetrics($storageDir, $this->logger);

        // Create or resume session
        if ($sessionId) {
            try {
                $session = $this->sessionManager->resumeSession($sessionId);
                $io->success("Resumed session: {$sessionId}");
            } catch (\Exception $e) {
                $io->error("Failed to resume session: {$e->getMessage()}");
                $session = $this->sessionManager->createSession('interactive-shell-' . date('YmdHis'));
                $io->note("Created new session instead: {$session['id']}");
            }
        } else {
            $session = $this->sessionManager->createSession('interactive-shell-' . date('YmdHis'));
            $io->success("Created new session: {$session['id']}");
        }

        $sessionId = $session['id'];
        $history = [];

        $io->note([
            'Enter your prompts to send to Claude. Special commands:',
            '  !help - Show this help',
            '  !exit or !quit - Exit the shell',
            '  !history - Show command history',
            '  !clear - Clear the screen',
            '  !thinking <default|extended> - Set thinking mode',
            '  !file <path> - Send a file to Claude',
            '  !dir <path> - Send a directory to Claude',
            '  !session <action> - Session management (list, create, resume, delete)',
        ]);

        $thinkingMode = 'default';

        while (true) {
            $input = $io->ask("Claude> ");

            if (empty($input)) {
                continue;
            }

            // Add to history
            $history[] = $input;

            // Process special commands
            if ($input === '!exit' || $input === '!quit') {
                break;
            } elseif ($input === '!help') {
                $this->showHelp($io);
                continue;
            } elseif ($input === '!history') {
                $this->showHistory($io, $history);
                continue;
            } elseif ($input === '!clear') {
                system('clear');
                continue;
            } elseif (strpos($input, '!thinking') === 0) {
                $parts = explode(' ', $input, 2);
                if (isset($parts[1])) {
                    $mode = strtolower(trim($parts[1]));
                    if ($mode === 'default' || $mode === 'extended') {
                        $thinkingMode = $mode;
                        $io->success("Thinking mode set to: $thinkingMode");
                    } else {
                        $io->error("Invalid thinking mode. Use 'default' or 'extended'");
                    }
                } else {
                    $io->note("Current thinking mode: $thinkingMode");
                }
                continue;
            } elseif (strpos($input, '!file') === 0) {
                $parts = explode(' ', $input, 2);
                if (isset($parts[1])) {
                    $filePath = trim($parts[1]);
                    $this->sendFile($io, $sessionId, $filePath);
                } else {
                    $io->error("Missing file path. Usage: !file <path>");
                }
                continue;
            } elseif (strpos($input, '!dir') === 0) {
                $parts = explode(' ', $input, 2);
                if (isset($parts[1])) {
                    $dirPath = trim($parts[1]);
                    $this->sendDirectory($io, $sessionId, $dirPath);
                } else {
                    $io->error("Missing directory path. Usage: !dir <path>");
                }
                continue;
            } elseif (strpos($input, '!session') === 0) {
                $parts = explode(' ', $input, 3);
                if (isset($parts[1])) {
                    $action = strtolower(trim($parts[1]));
                    $this->handleSessionAction($io, $action, $parts[2] ?? null);
                } else {
                    $io->error("Missing session action. Usage: !session <list|create|resume|delete> [id]");
                }
                continue;
            }

            // Regular prompt - check cache first
            $codeContext = []; // In a real implementation, this would contain current context
            $cachedResponse = $this->cache->getCachedResponse($input, $codeContext);

            if ($cachedResponse) {
                $io->note("Retrieved from cache");
                $this->displayResponse($io, $cachedResponse);
                continue;
            }

            // Send to Claude with progress indication
            $io->writeln("<comment>Sending to Claude...</comment>");

            try {
                $startTime = microtime(true);
                $response = $this->client->sendPrompt($sessionId, $input, $thinkingMode === 'extended' ? 'extended_thinking' : 'default');
                $endTime = microtime(true);
                $responseTime = (int) (($endTime - $startTime) * 1000); // Convert to milliseconds

                // Add response time to the response for metrics
                $response['metrics'] = [
                    'response_time' => $responseTime
                ];

                // Cache the response for future use
                $this->cache->cacheResponse($input, $codeContext, $response);

                // Record metrics
                $this->metrics->recordPromptMetrics($sessionId, $input, $response, null, null, $responseTime);

                // Display response
                $this->displayResponse($io, $response);
            } catch (\Exception $e) {
                $io->error("Error: {$e->getMessage()}");
            }
        }

        $io->success("Exiting shell. Session ID: {$sessionId}");
        return Command::SUCCESS;
    }

    /**
     * Display Claude's response
     */
    private function displayResponse(SymfonyStyle $io, array $response): void
    {
        $content = $response['content'] ?? '';

        if (empty($content)) {
            $io->warning("Empty response received");
            return;
        }

        $io->writeln("\n<info>Claude:</info>");
        $io->writeln($content);
        $io->newLine();

        // Show metadata if available
        if (isset($response['metrics'])) {
            $io->writeln(sprintf(
                "<comment>Response time: %d ms</comment>",
                $response['metrics']['response_time']
            ));
        }
    }

    /**
     * Show help message
     */
    private function showHelp(SymfonyStyle $io): void
    {
        $io->section('Available Commands');
        $io->listing([
            '!help - Show this help',
            '!exit or !quit - Exit the shell',
            '!history - Show command history',
            '!clear - Clear the screen',
            '!thinking <default|extended> - Set thinking mode',
            '!file <path> - Send a file to Claude',
            '!dir <path> - Send a directory to Claude',
            '!session list - List available sessions',
            '!session create [name] - Create a new session',
            '!session resume <id> - Resume an existing session',
            '!session delete <id> - Delete a session',
        ]);
    }

    /**
     * Show command history
     */
    private function showHistory(SymfonyStyle $io, array $history): void
    {
        $io->section('Command History');

        if (empty($history)) {
            $io->writeln('No history yet');
            return;
        }

        foreach (array_slice($history, 0, -1) as $i => $cmd) {
            $io->writeln(sprintf('%d: %s', $i + 1, $cmd));
        }
    }

    /**
     * Send a file to Claude
     */
    private function sendFile(SymfonyStyle $io, string $sessionId, string $filePath): void
    {
        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return;
        }

        try {
            $io->writeln("<comment>Sending file to Claude...</comment>");
            $this->client->sendFile($sessionId, $filePath);
            $io->success("File sent: " . basename($filePath));
        } catch (\Exception $e) {
            $io->error("Failed to send file: {$e->getMessage()}");
        }
    }

    /**
     * Send a directory to Claude
     */
    private function sendDirectory(SymfonyStyle $io, string $sessionId, string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            $io->error("Directory not found: $dirPath");
            return;
        }

        try {
            $io->writeln("<comment>Sending directory to Claude...</comment>");
            $this->client->sendDirectory($sessionId, $dirPath);
            $io->success("Directory sent: " . basename($dirPath));
        } catch (\Exception $e) {
            $io->error("Failed to send directory: {$e->getMessage()}");
        }
    }

    /**
     * Handle session-related actions
     */
    private function handleSessionAction(SymfonyStyle $io, string $action, ?string $param = null): void
    {
        switch ($action) {
            case 'list':
                $sessions = $this->sessionManager->listSessions();
                $io->section('Available Sessions');

                if (empty($sessions)) {
                    $io->writeln('No sessions found');
                    return;
                }

                $rows = [];
                foreach ($sessions as $session) {
                    $rows[] = [
                        $session['id'],
                        $session['name'],
                        isset($session['created_at']) ? date('Y-m-d H:i:s', strtotime($session['created_at'])) : 'N/A'
                    ];
                }

                $io->table(['ID', 'Name', 'Created At'], $rows);
                break;

            case 'create':
                $name = $param ?? 'interactive-shell-' . date('YmdHis');
                $session = $this->sessionManager->createSession($name);
                $io->success("Created new session: {$session['id']} (name: $name)");
                break;

            case 'resume':
                if (empty($param)) {
                    $io->error("Session ID is required for resume action");
                    return;
                }

                try {
                    $session = $this->sessionManager->resumeSession($param);
                    $io->success("Resumed session: {$param}");
                } catch (\Exception $e) {
                    $io->error("Failed to resume session: {$e->getMessage()}");
                }
                break;

            case 'delete':
                if (empty($param)) {
                    $io->error("Session ID is required for delete action");
                    return;
                }

                try {
                    $result = $this->sessionManager->deleteSession($param);
                    if ($result) {
                        $io->success("Deleted session: {$param}");
                    } else {
                        $io->error("Failed to delete session");
                    }
                } catch (\Exception $e) {
                    $io->error("Failed to delete session: {$e->getMessage()}");
                }
                break;

            default:
                $io->error("Unknown session action: $action");
                break;
        }
    }
}
