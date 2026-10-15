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
use App\Module\Identity\Domain\DisplayName;
use App\Module\Identity\Domain\PlainPassword;
use App\Module\Identity\Domain\WeakPassword;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
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

    private const string DEFAULT_BASE_CURRENCY = 'EUR';

    private const array FROM_ENV_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private readonly ProvisionInitialWorkspace $provisionInitialWorkspace,
        private readonly DefineInitialPassword $defineInitialPassword,
        private readonly AuthenticationUserRepository $users,
        private readonly CommonPasswordList $commonPasswords,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
        parent::__construct();
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
                ? $this->valuesFromEnvironment()
                : $this->valuesFromPrompts($io);
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
            $password = $fromEnv ? $this->passwordFromEnvironment() : $this->askPassword($io);
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

    /**
     * @return array{string, string, string, string, string}
     */
    private function valuesFromPrompts(SymfonyStyle $io): array
    {
        return [
            self::prompt($io, 'Owner email address', null, self::assertEmail(...)),
            self::prompt($io, 'Owner display name', null, static fn (string $value): string => DisplayName::fromString($value)->value),
            self::prompt($io, 'Workspace name', null, self::assertNotBlank(...)),
            self::prompt($io, 'Workspace base currency (ISO 4217)', self::DEFAULT_BASE_CURRENCY, static fn (string $value): string => mb_strtoupper(trim($value))),
            $this->askPassword($io),
        ];
    }

    /**
     * Same domain rules as the prompts, so the two paths cannot drift.
     *
     * @return array{string, string, string, string, string}
     */
    private function valuesFromEnvironment(): array
    {
        return [
            self::fromEnvironment('CADRAN_SETUP_EMAIL', self::assertEmail(...)),
            self::fromEnvironment('CADRAN_SETUP_DISPLAY_NAME', static fn (string $value): string => DisplayName::fromString($value)->value),
            self::fromEnvironment('CADRAN_SETUP_WORKSPACE_NAME', self::assertNotBlank(...)),
            self::optionalFromEnvironment('CADRAN_SETUP_BASE_CURRENCY') ?? self::DEFAULT_BASE_CURRENCY,
            $this->passwordFromEnvironment(),
        ];
    }

    private function passwordFromEnvironment(): string
    {
        return self::fromEnvironment('CADRAN_SETUP_PASSWORD', fn (string $value): string => PlainPassword::chosen($value, $this->commonPasswords)->value);
    }

    /**
     * @param \Closure(string): string $normalize
     */
    private static function fromEnvironment(string $name, \Closure $normalize): string
    {
        $value = self::optionalFromEnvironment($name) ?? throw new \RuntimeException(sprintf('The %s environment variable is required.', $name));

        try {
            return $normalize($value);
        } catch (WeakPassword|\InvalidArgumentException $exception) {
            // Rule description only: the value, a password above all, is never echoed.
            throw new \RuntimeException(sprintf('%s is invalid: %s', $name, $exception->getMessage()));
        }
    }

    private static function optionalFromEnvironment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        $password = $io->askQuestion(self::hiddenQuestion('Owner password', self::validateWith(
            fn (string $value): string => PlainPassword::chosen($value, $this->commonPasswords)->value,
        )));
        $confirmation = $io->askQuestion(self::hiddenQuestion('Confirm the password', null));

        if (!is_string($password) || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
            throw new \RuntimeException('The two passwords do not match.');
        }

        return $password;
    }

    /**
     * Hidden with the fallback deliberately off. SymfonyStyle::askHidden() leaves
     * it on, so on a host without `stty` the prompt would silently downgrade to
     * an echoing one and print the password onto the terminal. Failing the setup
     * is the safer end of that trade.
     *
     * @param \Closure(mixed): string|null $validator
     */
    private static function hiddenQuestion(string $prompt, ?\Closure $validator): Question
    {
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator($validator);

        return $question;
    }

    /**
     * @param \Closure(string): string $normalize
     */
    private static function prompt(SymfonyStyle $io, string $question, ?string $default, \Closure $normalize): string
    {
        $answer = $io->ask($question, $default, self::validateWith($normalize));

        return is_string($answer) ? $answer : throw new \RuntimeException('An answer is required.');
    }

    /**
     * Wraps a normaliser into the shape the question helper expects: it must
     * return the accepted value and throw to re-ask. The domain rules stay the
     * single authority, so the prompt cannot drift from the API.
     *
     * @param \Closure(string): string $normalize
     *
     * @return \Closure(mixed): string
     */
    private static function validateWith(\Closure $normalize): \Closure
    {
        return static function (mixed $value) use ($normalize): string {
            try {
                return $normalize(is_string($value) ? $value : '');
            } catch (WeakPassword|\InvalidArgumentException $exception) {
                // The message describes the rule, never the value: a rejected
                // password must not be echoed back onto the terminal.
                throw new \RuntimeException($exception->getMessage());
            }
        };
    }

    private static function assertEmail(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if (false === filter_var($normalized, FILTER_VALIDATE_EMAIL) || mb_strlen($normalized) > 254) {
            throw new \InvalidArgumentException('A valid email address of at most 254 characters is required.');
        }

        return $normalized;
    }

    private static function assertNotBlank(string $value): string
    {
        $normalized = trim($value);
        if ('' === $normalized) {
            throw new \InvalidArgumentException('A value is required.');
        }

        return $normalized;
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
