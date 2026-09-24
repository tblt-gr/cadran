<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\RecapPreferences;
use App\Module\Reporting\Domain\RecapVisibility;

/**
 * Translates between the stored recap selection and its
 * `reporting_recap_preferences` row, in both directions.
 */
final readonly class RecapPreferencesRow
{
    /**
     * The workspace is compared, never taken from the row: a query that leaked
     * another workspace's selection must fail here instead of hydrating an
     * object that looks legitimate.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $knownAxes
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $knownAxes): RecapPreferences
    {
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A recap preference row escaped its requested workspace.');
        }

        return new RecapPreferences(
            $workspace,
            RecapVisibility::of(
                self::strings($row['visible_category_ids'] ?? null),
                self::strings($row['visible_axes'] ?? null),
                $knownAxes,
            ),
            (int) self::text($row['version'] ?? null),
            new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
        );
    }

    /** @return array<string, string> */
    public static function columns(RecapPreferences $preferences): array
    {
        if (null === $preferences->updatedAt) {
            throw new \UnexpectedValueException('A stored recap selection carries the instant it was saved.');
        }

        return [
            'visible_category_ids' => json_encode($preferences->visibility->categoryIds, JSON_THROW_ON_ERROR),
            'visible_axes' => json_encode($preferences->visibility->axes, JSON_THROW_ON_ERROR),
            'version' => (string) $preferences->version,
            'updated_at' => $preferences->updatedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    public static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }

        throw new \UnexpectedValueException('Expected a textual recap preference column.');
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $decoded = json_decode(self::text($value), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Expected a recap preference array column.');
        }

        return array_values(array_map(self::text(...), $decoded));
    }
}
