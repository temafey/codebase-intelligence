<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Analysis;

use CodebaseIntelligence\Utils\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Creates differential updates for efficient codebase updating
 */
class DifferentialUpdater
{
    private LoggerInterface $logger;
    private Filesystem $filesystem;
    private string $codebasePath;
    private string $versionFile;
    private string $diffDir;

    public function __construct(
        string $codebasePath,
        string $storageDir,
        ?LoggerInterface $logger = null
    ) {
        $this->codebasePath = $codebasePath;
        $this->logger = $logger ?? new Logger('differential-updater');
        $this->filesystem = new Filesystem();

        // Create storage directory if it doesn't exist
        $this->filesystem->mkdir($storageDir, 0755);

        $this->versionFile = $storageDir . '/version_manifest.md5';
        $this->diffDir = $storageDir . '/diffs';

        // Create diffs directory if it doesn't exist
        $this->filesystem->mkdir($this->diffDir, 0755);
    }

    /**
     * Create a new version manifest
     */
    public function createVersionManifest(): string
    {
        $this->logger->info("Creating version manifest", ['codebase' => $this->codebasePath]);

        $outputFile = $this->versionFile . '.new';

        // Use md5sum command to generate checksums for all files
        $process = new Process([
            'find',
            $this->codebasePath,
            '-type', 'f',
            '-exec', 'md5sum', '{}', ';'
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error("Failed to create version manifest", [
                'error' => $process->getErrorOutput()
            ]);
            throw new \RuntimeException("Failed to create version manifest: " . $process->getErrorOutput());
        }

        file_put_contents($outputFile, $process->getOutput());
        $this->logger->debug("Version manifest created", ['file' => $outputFile]);

        return $outputFile;
    }

    /**
     * Find changes since last update
     */
    public function findChanges(): array
    {
        $this->logger->info("Finding changes since last update");

        // Check if we have a previous version manifest
        if (!file_exists($this->versionFile)) {
            $this->logger->warning("No previous version manifest found, treating all files as new");
            return $this->getAllFiles();
        }

        // Create new version manifest
        $newVersionFile = $this->createVersionManifest();

        // Compare with previous version
        $process = new Process([
            'comm',
            '-23',
            $newVersionFile,
            $this->versionFile
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error("Failed to find changes", [
                'error' => $process->getErrorOutput()
            ]);
            throw new \RuntimeException("Failed to find changes: " . $process->getErrorOutput());
        }

        // Parse output and extract changed files
        $changes = [];
        $lines = explode("\n", $process->getOutput());

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // Extract file path from md5sum output
            if (preg_match('/^\w+\s+(.+)$/', $line, $matches)) {
                $changes[] = $matches[1];
            }
        }

        // Update version manifest
        $this->filesystem->rename($newVersionFile, $this->versionFile);

        $this->logger->info("Found changes", ['count' => count($changes)]);

        return $changes;
    }

    /**
     * Generate diffs for changed files
     */
    public function generateDiffs(array $changedFiles): array
    {
        $this->logger->info("Generating diffs for changed files", ['count' => count($changedFiles)]);

        $diffs = [];

        foreach ($changedFiles as $file) {
            // Skip files that don't exist anymore
            if (!file_exists($file)) {
                $this->logger->warning("File no longer exists, skipping", ['file' => $file]);
                continue;
            }

            // Create a safe filename for the diff
            $relativePath = str_replace($this->codebasePath . '/', '', $file);
            $safeDiffName = str_replace(['/', '\\'], '_', $relativePath) . '.diff';
            $diffFile = $this->diffDir . '/' . $safeDiffName;

            // Use git to generate context-aware diff if available
            if ($this->isGitRepository()) {
                $this->generateGitDiff($file, $diffFile);
            } else {
                // Otherwise just use the file content as is
                $this->createFullContentDiff($file, $diffFile);
            }

            $diffs[] = $diffFile;
        }

        $this->logger->info("Generated diffs", ['count' => count($diffs)]);

        return $diffs;
    }

    /**
     * Generate git diff for a file
     */
    private function generateGitDiff(string $file, string $diffFile): bool
    {
        $process = new Process([
            'git',
            'diff',
            '--unified=3',
            'HEAD~1',
            'HEAD',
            '--',
            $file
        ]);
        $process->setWorkingDirectory($this->codebasePath);
        $process->run();

        if (!$process->isSuccessful() || empty($process->getOutput())) {
            // If no diff, the file might be new or not in git yet
            $this->createFullContentDiff($file, $diffFile);
            return false;
        }

        file_put_contents($diffFile, $process->getOutput());
        return true;
    }

    /**
     * Create a full-content diff for a file (when git is not available)
     */
    private function createFullContentDiff(string $file, string $diffFile): void
    {
        $relativePath = str_replace($this->codebasePath . '/', '', $file);
        $content = file_get_contents($file);

        // Create a pseudo-diff format
        $diff = "--- /dev/null\n";
        $diff .= "+++ b/$relativePath\n";
        $diff .= "@@ -0,0 +1," . count(explode("\n", $content)) . " @@\n";

        // Add file content with + prefix on each line
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $diff .= "+$line\n";
        }

        file_put_contents($diffFile, $diff);
    }

    /**
     * Check if the codebase is a git repository
     */
    private function isGitRepository(): bool
    {
        $process = new Process(['git', 'status']);
        $process->setWorkingDirectory($this->codebasePath);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get all files in the codebase
     */
    private function getAllFiles(): array
    {
        $process = new Process([
            'find',
            $this->codebasePath,
            '-type', 'f',
            '-not', '-path', '*/\.*',
            '-not', '-path', '*/vendor/*',
            '-not', '-path', '*/node_modules/*'
        ]);

        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error("Failed to list files", [
                'error' => $process->getErrorOutput()
            ]);
            throw new \RuntimeException("Failed to list files: " . $process->getErrorOutput());
        }

        return array_filter(explode("\n", $process->getOutput()));
    }

    /**
     * Generate a unified update package with all changed files
     */
    public function generateUpdatePackage(): string
    {
        $changedFiles = $this->findChanges();
        $diffs = $this->generateDiffs($changedFiles);

        // Create a combined diff file
        $packageFile = $this->diffDir . '/update_' . date('YmdHis') . '.diff';
        $combined = '';

        foreach ($diffs as $diff) {
            $combined .= file_get_contents($diff) . "\n\n";
        }

        file_put_contents($packageFile, $combined);

        $this->logger->info("Generated update package", [
            'package' => $packageFile,
            'changed_files' => count($changedFiles)
        ]);

        return $packageFile;
    }
}