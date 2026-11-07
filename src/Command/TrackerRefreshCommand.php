<?php

declare(strict_types=1);

namespace Fullmetrix\SyliusPlugin\Command;

use Fullmetrix\SyliusPlugin\Service\LocalTrackerRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fullmetrix:tracker:refresh', description: 'Refresh the Fullmetrix tracking script served from the store.')]
final class TrackerRefreshCommand extends Command
{
    public function __construct(private readonly LocalTrackerRefresher $refresher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore the pause and the 10 minute interval.')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Delete the local files and go back to the Fullmetrix address.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (true === $input->getOption('remove')) {
            $this->refresher->remove();
            $output->writeln('removed', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        $result = $this->refresher->refresh(true === $input->getOption('force'));
        $output->writeln(trim($result['outcome'] . ' ' . ($result['path'] ?? '') . ' ' . ($result['error'] ?? '')), OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
