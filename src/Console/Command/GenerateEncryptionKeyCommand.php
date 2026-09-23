<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Console\Command;

use ParagonIE\Halite\Alerts\CannotPerformOperation;
use ParagonIE\Halite\Alerts\InvalidKey;
use ParagonIE\Halite\KeyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Same behaviour as Sylius' `sylius:payment:generate-key`, for the key that encrypts the
 * carrier credentials.
 *
 * @internal
 */
#[AsCommand(
    name: 'jpmmartin:carrier:generate-key',
    description: 'Generate the key that encrypts the carrier credentials.',
)]
final class GenerateEncryptionKeyCommand extends Command
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $keyPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite an existing key file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (false === $input->getOption('overwrite') && $this->filesystem->exists($this->keyPath)) {
            $io->warning(sprintf(
                'Key file "%s" already exists. Replacing it makes the carrier credentials already stored unreadable: they will have to be entered again.',
                $this->keyPath,
            ));

            if (false === $io->confirm('Do you want to overwrite it?', false)) {
                $io->info('Key generation has been canceled.');

                return Command::SUCCESS;
            }
        }

        try {
            $key = KeyFactory::generateEncryptionKey();
        } catch (CannotPerformOperation|InvalidKey|\TypeError) {
            $io->error('The key could not be generated. Make sure PHP supports libsodium.');

            return Command::FAILURE;
        }

        try {
            $this->filesystem->mkdir(\dirname($this->keyPath));
            $this->filesystem->touch($this->keyPath);
            $saved = KeyFactory::save($key, $this->keyPath);
        } catch (IOException) {
            $saved = false;
        }

        if (false === $saved) {
            $io->error(sprintf('The key could not be saved. Make sure the directory "%s" is writable.', \dirname($this->keyPath)));

            return Command::FAILURE;
        }

        $io->success(sprintf('The key has been generated and saved in "%s".', $this->keyPath));

        return Command::SUCCESS;
    }
}
