<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Console;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\ConsoleActor;
use App\Module\Foundation\Application\ConsoleActorRefused;
use App\Module\Reporting\Application\CaptureMissingSnapshots;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cadran:reporting:capture-snapshots',
    description: 'Capture the missing or outdated snapshots of the closed months ahead of the first report read.',
)]
final class CaptureSnapshotsCommand extends Command
{
    public function __construct(
        private readonly ConsoleActor $actor,
        private readonly CallerWorkspaceContext $caller,
        private readonly CaptureMissingSnapshots $capture,
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

        $result = ($this->capture)($this->caller->resolveContext()->workspace);
        // Counts only: no amount or label reaches the console or its logs.
        $io->success(sprintf('%d month(s) captured, %d skipped (reopened meanwhile).', $result->captured, $result->skipped));

        return Command::SUCCESS;
    }
}
