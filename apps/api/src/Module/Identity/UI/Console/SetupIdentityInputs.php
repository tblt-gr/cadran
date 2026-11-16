<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Console;

use App\Module\Identity\Domain\CommonPasswordList;
use App\Module\Identity\Domain\DisplayName;
use App\Module\Identity\Domain\PlainPassword;
use App\Module\Identity\Domain\WeakPassword;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Collects the owner values of {@see SetupIdentityCommand}, from interactive prompts or from the
 * CADRAN_SETUP_* variables, applying the same domain rules on both paths. Provisioning stays in
 * the command.
 */
final readonly class SetupIdentityInputs
{
    private const string DEFAULT_BASE_CURRENCY = 'EUR';

    public function __construct(private CommonPasswordList $commonPasswords)
    {
    }

    /**
     * @return array{string, string, string, string, string}
     */
    public function valuesFromPrompts(SymfonyStyle $io): array
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
    public function valuesFromEnvironment(): array
    {
        return [
            self::fromEnvironment('CADRAN_SETUP_EMAIL', self::assertEmail(...)),
            self::fromEnvironment('CADRAN_SETUP_DISPLAY_NAME', static fn (string $value): string => DisplayName::fromString($value)->value),
            self::fromEnvironment('CADRAN_SETUP_WORKSPACE_NAME', self::assertNotBlank(...)),
            self::optionalFromEnvironment('CADRAN_SETUP_BASE_CURRENCY') ?? self::DEFAULT_BASE_CURRENCY,
            $this->passwordFromEnvironment(),
        ];
    }

    public function passwordFromEnvironment(): string
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

    public function askPassword(SymfonyStyle $io): string
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
}
