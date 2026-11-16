<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Console;

use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\DefineInitialPassword;
use App\Module\Identity\Application\DefineInitialPasswordInput;
use App\Module\Identity\Application\InitialPasswordAlreadyDefined;
use App\Module\Identity\Application\InitialProvisioningAlreadyCompleted;
use App\Module\Identity\Application\InitialWorkspaceProvisioningInput;
use App\Module\Identity\Application\ProvisionInitialWorkspace;
use App\Module\Identity\Domain\CommonPasswordList;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-time local setup: the owner account, their workspace and their password.
 *
 * Everything is asked interactively rather than taken from arguments. A
 * positional password would land in the shell history and in the process list
 * of every user on the host, and the other four values are typed once in the
 * lifetime of an install, so a prompt costs nothing and documents itself.
 *
 * `--from-env` is the one non-interactive path, for provisioning disposable dev
 * and test stacks (browser end-to-end runs). It reads the same values from
 * CADRAN_SETUP_* variables, is refused outside dev and test, and applies the
 * very same domain validation as the prompts.
 */
#[AsCommand(
    name: 'cadran:identity:setup',
    description: 'Set up the one-time local owner, workspace and password.',
)]
final class SetupIdentityCommand extends Command
{
    /**
     * Distinct from Command::FAILURE so a bootstrap script can tell an
     * infrastructure error (retry, fix credentials, run migrations) apart from
     * "already provisioned" (safe to continue).
     */
    private const int EXIT_INFRASTRUCTURE_FAILURE = 3;

    private const array FROM_ENV_ENVIRONMENTS = ['dev', 'test'];

    private readonly SetupIdentityInputs $inputs;

    public function __construct(
        private readonly ProvisionInitialWorkspace $provisionInitialWorkspace,
        private readonly DefineInitialPassword $defineInitialPassword,
        private readonly AuthenticationUserRepository $users,
        CommonPasswordList $commonPasswords,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
        parent::__construct();
        $this->inputs = new SetupIdentityInputs($commonPasswords);
    }

    protected function configure(): void
    {
        $this->addOption(
            'from-env',
            null,
            InputOption::VALUE_NONE,
            'Read the owner values from CADRAN_SETUP_EMAIL, _DISPLAY_NAME, _WORKSPACE_NAME, _PASSWORD and optional _BASE_CURRENCY (dev and test only).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fromEnv = true === $input->getOption('from-env');

        if ($fromEnv && !in_array($this->environment, self::FROM_ENV_ENVIRONMENTS, true)) {
            $io->error('--from-env only runs in the dev and test environments.');

            return Command::FAILURE;
        }

        try {
            $owner = $this->users->findProvisionedOwner();
        } catch (\Throwable $exception) {
            return $this->reportInfrastructureFailure($io, $exception);
        }

        if (null !== $owner && $owner->hasPassword) {
            $io->error('Initial provisioning has already been completed.');

            return Command::FAILURE;
        }

        if (!$fromEnv && !$input->isInteractive()) {
            $io->error('This command prompts for the owner credentials and needs an interactive terminal.');

            return Command::INVALID;
        }

        if (!$fromEnv) {
            $io->title('Cadran setup');
        }

        // An owner with no password is the debris of a run that died between
        // the two transactions below. Finishing it on the host is the whole
        // point of this command: the alternative path, the unauthenticated
        // first-run endpoint, is exactly the network exposure setup avoids.
        if (null !== $owner) {
            $io->note('An owner exists without a password. Setting it now completes the interrupted setup.');

            return $this->completePassword($io, $fromEnv);
        }

        try {
            [$email, $displayName, $workspaceName, $baseCurrency, $password] = $fromEnv
                ? $this->inputs->valuesFromEnvironment()
                : $this->inputs->valuesFromPrompts($io);
        } catch (\RuntimeException $exception) {
            // Nothing has been written yet. The message of a validation failure
            // names a variable or describes a rule, never the rejected value.
            $io->error($fromEnv ? $exception->getMessage() : 'Setup was interrupted.');

            return Command::INVALID;
        }

        try {
            ($this->provisionInitialWorkspace)(new InitialWorkspaceProvisioningInput(
                email: $email,
                displayName: $displayName,
                workspaceName: $workspaceName,
                baseCurrency: $baseCurrency,
            ));
            ($this->defineInitialPassword)(new DefineInitialPasswordInput($password));
        } catch (InitialProvisioningAlreadyCompleted|InitialPasswordAlreadyDefined) {
            $io->error('Initial provisioning has already been completed.');

            return Command::FAILURE;
        } catch (\InvalidArgumentException) {
            $io->error('Invalid initial provisioning parameters.');

            return Command::INVALID;
        } catch (\Throwable $exception) {
            return $this->reportInfrastructureFailure($io, $exception);
        }

        $io->success('The owner, their workspace and their password are set up. Sign in from the web interface.');

        return Command::SUCCESS;
    }

    /**
     * Sets the password of an owner that was created by an interrupted run.
     */
    private function completePassword(SymfonyStyle $io, bool $fromEnv): int
    {
        try {
            $password = $fromEnv ? $this->inputs->passwordFromEnvironment() : $this->inputs->askPassword($io);
        } catch (\RuntimeException $exception) {
            $io->error($fromEnv ? $exception->getMessage() : 'Setup was interrupted.');

            return Command::INVALID;
        }

        try {
            ($this->defineInitialPassword)(new DefineInitialPasswordInput($password));
        } catch (InitialPasswordAlreadyDefined) {
            $io->error('Initial provisioning has already been completed.');

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            return $this->reportInfrastructureFailure($io, $exception);
        }

        $io->success('The owner password is set. Sign in from the web interface.');

        return Command::SUCCESS;
    }

    private function reportInfrastructureFailure(SymfonyStyle $io, \Throwable $exception): int
    {
        // Render only the exception class, never the driver message: the latter
        // can echo the owner email or other identity data onto stderr and into
        // container logs. The class name alone still tells an operator whether
        // to run migrations, fix credentials or retry.
        $io->error('Initial provisioning failed.');
        $io->writeln(sprintf('<comment>%s</comment>', $exception::class));

        return self::EXIT_INFRASTRUCTURE_FAILURE;
    }
}
