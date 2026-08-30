<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Console;

use App\Module\Identity\Application\InitialProvisioningAlreadyCompleted;
use App\Module\Identity\Application\InitialWorkspaceProvisioningInput;
use App\Module\Identity\Application\ProvisionInitialWorkspace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cadran:identity:provision-initial-owner',
    description: 'Provision the one-time local owner and workspace.',
)]
final class ProvisionInitialOwnerCommand extends Command
{
    /**
     * Distinct from Command::FAILURE so a bootstrap script can tell an
     * infrastructure error (retry, fix credentials, run migrations) apart from
     * "already provisioned" (safe to continue).
     */
    private const int EXIT_INFRASTRUCTURE_FAILURE = 3;

    public function __construct(private readonly ProvisionInitialWorkspace $provisionInitialWorkspace)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Initial owner email address')
            ->addArgument('workspace-name', InputArgument::REQUIRED, 'Workspace name')
            ->addArgument('display-name', InputArgument::REQUIRED, 'Initial owner display name')
            ->addArgument('base-currency', InputArgument::REQUIRED, 'Workspace ISO 4217 base currency');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            ($this->provisionInitialWorkspace)(new InitialWorkspaceProvisioningInput(
                email: $this->stringArgument($input, 'email'),
                displayName: $this->stringArgument($input, 'display-name'),
                workspaceName: $this->stringArgument($input, 'workspace-name'),
                baseCurrency: $this->stringArgument($input, 'base-currency'),
            ));
        } catch (InitialProvisioningAlreadyCompleted) {
            $io->error('Initial provisioning has already been completed.');

            return Command::FAILURE;
        } catch (\InvalidArgumentException) {
            $io->error('Invalid initial provisioning parameters.');

            return Command::INVALID;
        } catch (\Throwable $exception) {
            // Render only the exception class, never the driver message: the
            // latter can echo the owner email or other identity data onto
            // stderr and into container logs. The class name alone still tells
            // an operator whether to run migrations, fix credentials or retry.
            $io->error('Initial provisioning failed.');
            $io->writeln(sprintf('<comment>%s</comment>', $exception::class));

            return self::EXIT_INFRASTRUCTURE_FAILURE;
        }

        $io->success('Initial owner and workspace provisioned.');

        return Command::SUCCESS;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('The "%s" argument must be a string.', $name));
        }

        return $value;
    }
}
