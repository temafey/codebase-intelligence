<?php


declare(strict_types=1);

namespace CodebaseIntelligence\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use CodebaseIntelligence\Cache\ResponseCache;
use Psr\Log\LoggerInterface;

/**
 * Command for managing response cache
 */
#[AsCommand(name: 'cache:clear')]
class CacheClearCommand extends Command
{
    protected static $defaultDescription = 'Clear response cache';

    private LoggerInterface $logger;
    private array $config;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        parent::__construct();
        $this->config = $config;
        $this->logger = $logger ?? new Logger('cache-command');
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
                'Type of cache to clear (all, responses, sessions)',
                'all'
            )
            ->addOption(
                'older-than',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Clear items older than X days',
                '0'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cache Management');

        $storageDir = $input->getOption('storage-dir');
        $type = $input->getOption('type');
        $olderThan = (int)$input->getOption('older-than');

        if (!is_dir($storageDir)) {
            $io->error("Storage directory not found: $storageDir");
            return Command::FAILURE;
        }

        $cacheConfig = array_merge(
            $this->config,
            ['cache_storage' => 'filesystem']
        );

        $cache = new ResponseCache($cacheConfig, $this->logger);

        $io->section('Clearing Cache');

        if ($type === 'all' || $type === 'responses') {
            $count = $this->clearResponseCache($cache, $storageDir, $olderThan);
            $io->success(sprintf(
                'Cleared %d response cache items%s',
                $count,
                $olderThan > 0 ? " older than $olderThan days" : ''
            ));
        }

        if ($type === 'all' || $type === 'sessions') {
            $count = $this->clearSessionCache($storageDir, $olderThan);
            $io->success(sprintf(
                'Cleared %d session cache items%s',
                $count,
                $olderThan > 0 ? " older than $olderThan days" : ''
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Clear response cache
     */
    private function clearResponseCache(ResponseCache $cache, string $storageDir, int $olderThan = 0): int
    {
        $cacheDir = $storageDir . '/cache';
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $count = 0;
        $threshold = $olderThan > 0 ? time() - ($olderThan * 86400) : 0;

        $files = glob($cacheDir . '/*.cache');
        foreach ($files as $file) {
            if ($olderThan > 0) {
                $mtime = filemtime($file);
                if ($mtime > $threshold) {
                    continue;
                }
            }

            unlink($file);
            $count++;
        }

        return $count;
    }

    /**
     * Clear session cache
     */
    private function clearSessionCache(string $storageDir, int $olderThan = 0): int
    {
        $sessionsDir = $storageDir . '/sessions';
        if (!is_dir($sessionsDir)) {
            return 0;
        }

        $count = 0;
        $threshold = $olderThan > 0 ? time() - ($olderThan * 86400) : 0;

        $files = glob($sessionsDir . '/*.json');
        foreach ($files as $file) {
            if ($olderThan > 0) {
                $mtime = filemtime($file);
                if ($mtime > $threshold) {
                    continue;
                }
            }

            unlink($file);
            $count++;
        }

        return $count;
    }
}