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
use CodebaseIntelligence\Analysis\SemanticChunker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Command for ingesting a codebase into Claude
 */
#[AsCommand(name: 'ingest')]
class IngestCommand extends Command
{
    protected static $defaultDescription = 'Ingest your codebase into Claude for analysis';

    private LoggerInterface $logger;
    private AIModelClientInterface $client;
    private array $config;
    private ?SessionManager $sessionManager = null;
    private Filesystem $filesystem;

    public function __construct(
        AIModelClientInterface $client,
        array                  $config,
        ?LoggerInterface       $logger = null
    )
    {
        parent::__construct();
        $this->client = $client;
        $this->config = $config;
        $this->logger = $logger ?? new Logger('ingest-command');
        $this->filesystem = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addArgument(
                'codebase-path',
                InputArgument::OPTIONAL,
                'Path to the codebase you want to ingest',
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
                'max-chunks',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of chunks to send (for budget control)',
                '0'
            )
            ->addOption(
                'chunk-size',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Maximum size of each chunk in tokens (approximate)',
                '2000'
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_OPTIONAL,
                'File patterns to include (comma-separated)',
                $this->config['codebase_include_patterns'] ?? '*.php,*.js,*.html,*.css,*.md'
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_OPTIONAL,
                'File patterns to exclude (comma-separated)',
                $this->config['codebase_exclude_patterns'] ?? 'vendor/*,node_modules/*,tests/*,storage/*'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Primary language of the codebase',
                $this->config['codebase_language'] ?? 'php'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Ingesting Codebase into Claude');

        $codebasePath = $input->getArgument('codebase-path');
        $storageDir = $input->getOption('storage-dir');
        $maxChunks = (int)$input->getOption('max-chunks');
        $chunkSize = (int)$input->getOption('chunk-size');
        $includePatterns = explode(',', $input->getOption('include'));
        $excludePatterns = explode(',', $input->getOption('exclude'));
        $language = $input->getOption('language');

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

        // Create session
        try {
            $session = $this->sessionManager->createSession('codebase-ingest-' . date('YmdHis'));
            $sessionId = $session['id'];
            $io->success("Created new session: $sessionId");
        } catch (\Exception $e) {
            $io->error("Failed to create session: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Prepare codebase overview
        $io->section('Preparing Codebase Overview');

        // Generate codebase structure
        $structure = $this->getCodebaseStructure($codebasePath, $includePatterns, $excludePatterns);

        // Create overview document
        $overviewContent = $this->createCodebaseOverview($codebasePath, $structure, $language);
        $overviewFile = $storageDir . '/docs/codebase_overview.md';

        $this->filesystem->mkdir(dirname($overviewFile));
        file_put_contents($overviewFile, $overviewContent);

        $io->success("Created codebase overview: $overviewFile");

        // Send overview to Claude
        $io->section('Sending Codebase Overview to Claude');

        try {
            $this->client->sendFile($sessionId, $overviewFile, "This is an overview of the codebase structure");
            $io->success("Sent codebase overview to Claude");
        } catch (\Exception $e) {
            $io->error("Failed to send overview: {$e->getMessage()}");
            $io->note("Continuing with codebase ingestion...");
        }

        // Create semantic chunks
        $io->section('Creating Semantic Chunks');

        // Configure semantic chunker
        $semanticChunkerConfig = [
            'codebase_language' => $language,
            'codebase_include_patterns' => $input->getOption('include'),
            'codebase_exclude_patterns' => $input->getOption('exclude'),
        ];

        $chunker = new SemanticChunker($semanticChunkerConfig, null, $this->logger);

        $io->text('Analyzing codebase and creating semantic chunks...');
        $chunks = $chunker->createSemanticChunks($codebasePath, $chunkSize);

        $io->success(sprintf('Created %d semantic chunks', count($chunks)));

        // Limit number of chunks if requested
        if ($maxChunks > 0 && count($chunks) > $maxChunks) {
            $io->warning(sprintf(
                'Limiting to %d chunks out of %d (as requested)',
                $maxChunks,
                count($chunks)
            ));
            $chunks = array_slice($chunks, 0, $maxChunks);
        }

        // Send chunks to Claude
        $io->section('Sending Chunks to Claude');
        $io->progressStart(count($chunks));

        $chunksDir = $storageDir . '/chunks';
        $this->filesystem->mkdir($chunksDir);

        $chunkFiles = [];
        $success = true;

        foreach ($chunks as $index => $chunk) {
            $chunkFile = $chunksDir . '/chunk_' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '.md';
            file_put_contents($chunkFile, $chunk['content']);
            $chunkFiles[] = $chunkFile;

            $desc = sprintf(
                "Chunk %d/%d: Contains code from %s files (%s)",
                $index + 1,
                count($chunks),
                count($chunk['files']),
                implode(', ', array_slice($chunk['files'], 0, 3)) . (count($chunk['files']) > 3 ? '...' : '')
            );

            try {
                $this->client->sendFile($sessionId, $chunkFile, $desc);
                $io->progressAdvance();
            } catch (\Exception $e) {
                $io->newLine();
                $io->error("Failed to send chunk {$index}: {$e->getMessage()}");
                $success = false;
                break;
            }
        }

        $io->progressFinish();

        if ($success) {
            // Final prompt to Claude
            $io->section('Finalizing Ingestion');

            $finalPrompt = <<<EOT
I've now provided you with a comprehensive view of our codebase. This includes:

1. A structural overview of the project
2. Semantic chunks containing the actual code

Based on this information, please:

1. Confirm your understanding of the overall architecture
2. Identify the core components and their relationships
3. Note any potential areas for improvement or optimization
4. Provide any insights about the code organization and structure

This will serve as a reference point for our future discussions about the codebase.
EOT;

            try {
                $response = $this->client->sendPrompt($sessionId, $finalPrompt);
                $io->text($response['content'] ?? 'No response from Claude');

                // Save response
                $summaryFile = $storageDir . '/docs/ingestion_summary.md';
                file_put_contents($summaryFile, $response['content'] ?? '');

                $io->success([
                    "Codebase successfully ingested into Claude!",
                    "Session ID: $sessionId",
                    "Summary saved to: $summaryFile"
                ]);

                return Command::SUCCESS;
            } catch (\Exception $e) {
                $io->error("Failed to send final prompt: {$e->getMessage()}");
                $io->warning("Codebase was partially ingested. You can still use the session.");
                return Command::FAILURE;
            }
        } else {
            $io->warning([
                "Codebase was partially ingested due to errors.",
                "Session ID: $sessionId"
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Get codebase structure
     */
    private function getCodebaseStructure(string $codebasePath, array $includePatterns, array $excludePatterns): array
    {
        $finder = new Finder();
        $finder->in($codebasePath);

        // Apply include patterns
        $finder->name($includePatterns);

        // Apply exclude patterns
        foreach ($excludePatterns as $pattern) {
            $finder->notPath($pattern);
        }

        // Count files by type
        $filesByType = [];
        $totalFiles = 0;
        $totalDirectories = new \SplObjectStorage();

        foreach ($finder as $file) {
            $extension = $file->getExtension();
            if (!isset($filesByType[$extension])) {
                $filesByType[$extension] = 0;
            }
            $filesByType[$extension]++;
            $totalFiles++;

            // Track unique directories
            $dir = $file->getPath();
            $totalDirectories[$dir] = true;
        }

        // Get simplified directory structure
        $process = new Process([
            'find',
            $codebasePath,
            '-type', 'd',
            '-not', '-path', '*/\.*',
            '-maxdepth', '3',  // Limit depth to make it more readable
        ]);
        $process->run();
        $directories = array_filter(explode("\n", $process->getOutput()));

        return [
            'total_files' => $totalFiles,
            'total_directories' => count($totalDirectories),
            'files_by_type' => $filesByType,
            'directories' => array_map(fn($dir) => str_replace($codebasePath . '/', '', $dir), $directories),
        ];
    }

    /**
     * Create codebase overview
     */
    private function createCodebaseOverview(string $codebasePath, array $structure, string $language): string
    {
        $projectName = basename($codebasePath);

        // Find potential README files
        $finder = new Finder();
        $finder->in($codebasePath)->depth('< 2')->name('README*');

        $readmeContent = '';
        foreach ($finder as $file) {
            $readmeContent = file_get_contents($file->getRealPath());
            break; // Just use the first one found
        }

        // Format the structure for display
        $filesByTypeText = '';
        foreach ($structure['files_by_type'] as $ext => $count) {
            $filesByTypeText .= "- .$ext: $count files\n";
        }

        // Format directories for display (limited to avoid overwhelming)
        $directoriesText = '';
        $topLevelDirs = array_filter($structure['directories'], function ($dir) {
            return substr_count($dir, '/') <= 1;
        });
        $topLevelDirs = array_slice($topLevelDirs, 0, 20); // Limit to 20 directories

        foreach ($topLevelDirs as $dir) {
            if (!empty($dir)) {
                $directoriesText .= "- $dir\n";
            }
        }

        if (count($topLevelDirs) < count($structure['directories'])) {
            $directoriesText .= "- ... and " . (count($structure['directories']) - count($topLevelDirs)) . " more\n";
        }

        $overview = <<<EOT
# Codebase Overview: $projectName

## Project Statistics
- **Total Files**: {$structure['total_files']}
- **Total Directories**: {$structure['total_directories']}
- **Primary Language**: $language

## Files by Type
$filesByTypeText

## Main Directory Structure
$directoriesText

## Project Description
EOT;

        // Add README content if found
        if (!empty($readmeContent)) {
            $overview .= "\n\n### From README:\n\n" . $readmeContent;
        } else {
            $overview .= "\n\n*No README found or unable to determine project description.*";
        }

        return $overview;
    }
}