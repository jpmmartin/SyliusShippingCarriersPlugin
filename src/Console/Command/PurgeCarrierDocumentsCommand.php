<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Console\Command;

use JpmMartin\SyliusShippingCarriersPlugin\Label\DocumentPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the labels and the customs documents that have been kept for longer than the retention allows.
 *
 * Meant for cron: a shop runs it as often as it likes, and running it twice in a row is not a problem, because
 * the second run finds nothing left to delete.
 */
#[AsCommand(
    name: 'jpmmartin:carrier:purge-documents',
    description: 'Delete the labels and customs documents that have been kept for longer than the retention allows.',
)]
final class PurgeCarrierDocumentsCommand extends Command
{
    public function __construct(
        private readonly DocumentPurger $purger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->purger->purge();

        if (0 === $report->deletedFiles && 0 === $report->failedFiles) {
            $io->success('There was nothing kept for longer than allowed.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d file(s) of %d shipment(s) have been deleted. What was issued, by whom and when is still recorded.',
            $report->deletedFiles,
            $report->shipments,
        ));

        if (0 === $report->failedFiles) {
            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            '%d file(s) could not be deleted and are still stored. The reason is in the log, and the next run tries again.',
            $report->failedFiles,
        ));

        return Command::FAILURE;
    }
}
