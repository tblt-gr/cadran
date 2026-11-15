<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Console;

use App\Module\Identity\Application\NewPasswordReused;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\ResetOwnerPassword;
use App\Module\Identity\Application\ResetOwnerPasswordInput;
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
 * Host-side recovery for a forgotten owner password.
 *
 * The password is prompted for, hidden, never taken from an argument: a
 * positional value would land in the shell history and the host process list.
 * `--from-env` is the one non-interactive path, for disposable dev and test
 * stacks only (same guard as `cadran:identity:setup --from-env`).
 *
 * Exit codes: 0 success, 1 refusal, 3 infrastructure failure.
 */
#[AsCommand(
    name: 'cadran:identity:reset-password',
    description: 'Replace the owner password and close every session.',
)]
final class ResetOwnerPasswordCommand extends Command
{
    private const int EXIT_INFRASTRUCTURE_FAILURE = 3;

    private const array FROM_ENV_ENVIRONMENTS = ['dev', 'test'];

    private const string ENV_VARIABLE = 'CADRAN_RESET_PASSWORD';

    public function __construct(
        private readonly ResetOwnerPassword $resetOwnerPassword,
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
            sprintf('Read the new password from %s (dev and test only).', self::ENV_VARIABLE),
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

        if (!$fromEnv && !$input->isInteractive()) {
            $io->error('This command prompts for the new password and needs an interactive terminal.');

            return Command::INVALID;
        }

        if ($fromEnv) {
            $password = getenv(self::ENV_VARIABLE);
            if (!is_string($password) || '' === $password) {
                $io->error(sprintf('The %s environment variable is required.', self::ENV_VARIABLE));

                return Command::FAILURE;
            }
        } else {
            $password = $this->askTwice($io);
            if (null === $password) {
                return Command::FAILURE;
            }
        }

        try {
            ($this->resetOwnerPassword)(new ResetOwnerPasswordInput($password));
        } catch (OwnerAccountNotProvisioned) {
            $io->error('No owner account is set up. Run `make setup` first.');

            return Command::FAILURE;
        } catch (NewPasswordReused) {
            $io->error('The new password must differ from the current one.');

            return Command::FAILURE;
        } catch (WeakPassword $exception) {
            // The message names the rule, never the rejected value.
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            // Class name only: a driver message can echo identity data.
            $io->error('The password reset failed.');
            $io->writeln(sprintf('<comment>%s</comment>', $exception::class));

            return self::EXIT_INFRASTRUCTURE_FAILURE;
        }

        $io->success('The owner password was replaced and every session was closed.');

        return Command::SUCCESS;
    }

    private function askTwice(SymfonyStyle $io): ?string
    {
        $first = $io->askQuestion(self::hiddenQuestion('New password'));
        $second = $io->askQuestion(self::hiddenQuestion('Repeat the new password'));

        if (!is_string($first) || !is_string($second) || !hash_equals($first, $second)) {
            $io->error('The two entries differ.');

            return null;
        }

        return $first;
    }

    /**
     * Hidden with the fallback off: on a host without `stty` the prompt must
     * fail rather than silently echo the password onto the terminal.
     */
    private static function hiddenQuestion(string $prompt): Question
    {
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        return $question;
    }
}
