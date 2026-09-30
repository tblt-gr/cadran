<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalScheduleInput;
use App\Module\Budget\Application\Goals\UpdateGoalInput;

final class GoalUpdateRequest
{
    /** @param array<mixed> $body */
    public static function from(string $id, array $body): UpdateGoalInput
    {
        self::allowed($body, ['label', 'targetAmount', 'ratio', 'version', 'metric', 'scope', 'schedule', 'effectiveFrom', 'reason'], ['label', 'version']);
        $target = null === ($body['targetAmount'] ?? null) ? null : self::object($body['targetAmount']);
        if (null !== $target) {
            self::allowed($target, ['value', 'assetCode'], ['value', 'assetCode']);
        }

        return new UpdateGoalInput(
            $id,
            self::integer($body, 'version'),
            self::string($body, 'label'),
            null === $target ? null : self::string($target, 'value'),
            null === $target ? null : self::string($target, 'assetCode'),
            self::nullableString($body, 'ratio'),
            array_key_exists('metric', $body) || array_key_exists('scope', $body),
            self::schedule($body),
            self::nullableString($body, 'effectiveFrom'),
            self::nullableString($body, 'reason'),
        );
    }

    /** @param array<mixed> $body */
    private static function schedule(array $body): ?GoalScheduleInput
    {
        if (null === ($body['schedule'] ?? null)) {
            return null;
        }
        try {
            $schedule = self::object($body['schedule']);
            self::allowed($schedule, ['mode', 'parts'], ['mode']);
            $parts = $schedule['parts'] ?? null;
            if (null !== $parts && (!is_array($parts) || !array_is_list($parts) || count($parts) > 24)) {
                throw new \UnexpectedValueException('Schedule parts are a short list of decimal strings.');
            }

            return new GoalScheduleInput(self::string($schedule, 'mode'), null === $parts ? null : array_map(static fn (mixed $part): string => is_string($part) ? $part : throw new \UnexpectedValueException('A schedule part is a decimal string.'), $parts));
        } catch (\UnexpectedValueException $error) {
            throw new GoalInputError('invalid_goal_schedule', $error->getMessage());
        }
    }

    /**
     * @param array<mixed> $body
     * @param list<string> $allowed
     * @param list<string> $required
     */
    private static function allowed(array $body, array $allowed, array $required): void
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \UnexpectedValueException('Unknown goal field.');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $body)) {
                throw new \UnexpectedValueException('Missing goal field.');
            }
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException('A goal object is required.');
        }

        $fields = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('A goal object is required.');
            }
            $fields[$key] = $item;
        }

        return $fields;
    }

    /** @param array<mixed> $body */
    private static function string(array $body, string $field): string
    {
        return is_string($body[$field] ?? null) ? $body[$field] : throw new \UnexpectedValueException('A goal string is required.');
    }

    /** @param array<mixed> $body */
    private static function nullableString(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException('A nullable goal string is required.');
        }

        return $value;
    }

    /** @param array<mixed> $body */
    private static function integer(array $body, string $field): int
    {
        return is_int($body[$field] ?? null) ? $body[$field] : throw new \UnexpectedValueException('A goal integer is required.');
    }
}
