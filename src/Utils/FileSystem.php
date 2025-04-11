<?php

declare(strict_types=1);

namespace CodebaseIntelligence\Utils;

use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Psr\Log\LoggerInterface;

/**
 * Extended filesystem utilities
 */
class FileSystem
{
    private SymfonyFilesystem $filesystem;
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->filesystem = new SymfonyFilesystem();
        $this->logger = $logger ?? new Logger('filesystem');
    }

    /**
     * Find files matching a pattern in a directory
     */
    public function findFiles(string $directory, string $pattern): array
    {
        $this->logger->debug("Finding files in directory", [
            'directory' => $directory,
            'pattern' => $pattern
        ]);

        $finder = new Finder();
        $finder->files()
               ->in($directory)
               ->name($pattern);

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        $this->logger->debug("Found files", ['count' => count($files)]);

        return $files;
    }

    /**
     * Create a temporary file with the given content
     */
    public function createTemporaryFile(string $content, string $extension = ''): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'claude_') . $extension;
        file_put_contents($tempFile, $content);

        $this->logger->debug("Created temporary file", ['file' => $tempFile]);

        return $tempFile;
    }

    /**
     * Get directory tree as a nested array
     */
    public function getDirectoryTree(string $directory, array $excludePatterns = []): array
    {
        $this->logger->debug("Getting directory tree", ['directory' => $directory]);

        $result = [];

        if (!is_dir($directory)) {
            $this->logger->error("Directory not found", ['directory' => $directory]);
            return $result;
        }

        $finder = new Finder();
        $finder->in($directory)
               ->depth('< 10'); // Limit depth to avoid excessive recursion

        // Add exclude patterns
        foreach ($excludePatterns as $pattern) {
            $finder->notPath($pattern);
        }

        // Separate directories and files
        $dirs = [];
        $files = [];

        foreach ($finder as $item) {
            $path = $item->getRelativePathname();

            if ($item->isDir()) {
                $dirs[$path] = [];
            } else {
                $files[] = $path;
            }
        }

        // Build the tree
        foreach ($files as $file) {
            $dir = dirname($file);
            $filename = basename($file);

            if ($dir === '.') {
                $result[$filename] = 'file';
            } else {
                $this->addToTree($result, $dir, $filename);
            }
        }

        $this->logger->debug("Directory tree built", [
            'directories' => count($dirs),
            'files' => count($files)
        ]);

        return $result;
    }

    /**
     * Add a file to the tree structure
     */
    private function addToTree(array &$tree, string $dir, string $file): void
    {
        $parts = explode('/', $dir);
        $current = &$tree;

        foreach ($parts as $part) {
            if ($part === '.') {
                continue;
            }

            if (!isset($current[$part])) {
                $current[$part] = [];
            }

            $current = &$current[$part];
        }

        $current[$file] = 'file';
    }
}
