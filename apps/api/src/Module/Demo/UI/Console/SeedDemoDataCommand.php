<?php

declare(strict_types=1);

namespace App\Module\Demo\UI\Console;

use App\Module\Demo\Application\DemoSeedRefused;
use App\Module\Demo\Application\ExtendDemoData;
use App\Module\Demo\Application\SeedDemoData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:demo:seed',
    description: 'Fill an empty workspace with a realistic household ledger for 2026 (dev and test only).',
)]
final class SeedDemoDataCommand extends Command
{
    private const array ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private readonly SeedDemoData $seed,
        private readonly ExtendDemoData $extend,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Owner email of the target workspace; defaults to the provisioned owner.');
        $this->addOption('extend', null, InputOption::VALUE_NONE, 'Top up an already seeded workspace to today instead of seeding an empty one (idempotent).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $io->error('The demo seed only runs in the dev and test environments.');

            return Command::FAILURE;
        }

        $email = $input->getArgument('email');
        try {
            $summary = true === $input->getOption('extend')
                ? ($this->extend)(is_string($email) ? $email : null)
                : ($this->seed)(is_string($email) ? $email : null);
        } catch (DemoSeedRefused $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Seeded %d accounts, %d categories, %d transactions, %d transfers, %d budget plans; %d month(s) closed.',
            $summary->accounts,
            $summary->categories,
            $summary->transactions,
            $summary->transfers,
            $summary->budgetPlans,
            $summary->closedMonths,
        ));

        return Command::SUCCESS;
    }
}
