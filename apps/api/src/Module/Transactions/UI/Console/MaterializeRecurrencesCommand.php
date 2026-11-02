<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Console;

use App\Module\Foundation\Application\ConsoleActor;
use App\Module\Foundation\Application\ConsoleActorRefused;
use App\Module\Transactions\Application\Recurrence\MaterializeRecurrenceOccurrences;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cadran:transactions:materialize-recurrences',
    description: 'Create the current month transactions of the auto-create recurrences and book the due ones.',
)]
final class MaterializeRecurrencesCommand extends Command
{
    public function __construct(
        private readonly ConsoleActor $actor,
        private readonly MaterializeRecurrenceOccurrences $materialize,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Owner email of the target workspace; defaults to the provisioned owner.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        try {
            $this->actor->assume(is_string($email) ? $email : null);
        } catch (ConsoleActorRefused $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        }

        $result = ($this->materialize)();
        // Counts only: a label or an amount never reaches the console or its logs.
        $io->success(sprintf(
            '%d recurrence(s) examined: %d transaction(s) created, %d booked, %d skipped (closed period), %d skipped (refused), for %s to %s.',
            $result->recurrencesExamined, $result->transactionsCreated, $result->transactionsBooked,
            $result->skippedClosedPeriod, $result->skippedRefused, $result->monthStart, $result->monthEnd,
        ));

        return Command::SUCCESS;
    }
}
