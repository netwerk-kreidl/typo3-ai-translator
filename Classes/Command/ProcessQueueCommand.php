<?php

declare(strict_types=1);

namespace Netwerk\AiTranslator\Command;

use Netwerk\AiTranslator\Queue\QueueProcessor;
use Netwerk\AiTranslator\Queue\QueueRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Arbeitet die Warteschlange der Massenübersetzung ab, z. B. per Cronjob alle 5 Minuten:
 *   vendor/bin/typo3 ai-translator:queue:process --time-limit=240
 */
#[AsCommand('ai-translator:queue:process', 'Arbeitet die Warteschlange der KI-Übersetzung ab')]
final class ProcessQueueCommand extends Command
{
    public function __construct(
        private readonly QueueProcessor $processor,
        private readonly QueueRepository $queue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Höchstens so viele Seiten verarbeiten (0 = alle)', '0')
            ->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Nach so vielen Sekunden keine neue Seite mehr beginnen (0 = unbegrenzt)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $io = new SymfonyStyle($input, $output);
        $limit = max(0, (int)$input->getOption('limit'));
        $timeLimit = max(0, (int)$input->getOption('time-limit'));
        $start = time();
        $processed = 0;
        $errors = 0;

        while (($limit === 0 || $processed < $limit) && ($timeLimit === 0 || time() - $start < $timeLimit)) {
            $result = $this->processor->processNext();
            if ($result === null) {
                break;
            }
            $processed++;
            if ($result['status'] === QueueRepository::ERROR) {
                $errors++;
                $io->writeln(sprintf('<error>✗</error> %s [%d]: %s', $result['title'], $result['page'], $result['message']));
            } else {
                $io->writeln(sprintf('<info>✓</info> %s [%d]: %s', $result['title'], $result['page'], $result['message']));
            }
        }

        $counts = $this->queue->getCounts();
        $io->writeln(sprintf('%d verarbeitet, %d Fehler, %d offen.', $processed, $errors, $counts[QueueRepository::PENDING]));
        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
