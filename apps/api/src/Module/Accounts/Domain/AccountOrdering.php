<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * The order of a sequence of siblings: the accounts inside one primary group
 * (or the ungrouped section), and the groups under one parent. One
 * implementation serves both, so they cannot drift apart.
 *
 * The order is display metadata kept apart from group hierarchy: a sequence
 * covers every member, hidden ones (archived groups) included, so moving one
 * visible row never reshuffles a row the owner cannot see.
 */
final class AccountOrdering
{
    /** The key of the ungrouped section, where a group identifier would sit. */
    public const string UNGROUPED_KEY = '00000000-0000-0000-0000-000000000000';

    public static function key(?string $groupId): string
    {
        return $groupId ?? self::UNGROUPED_KEY;
    }

    /**
     * @param list<string> $order    every member of the group, in saved order
     * @param ?string      $beforeId the member the moved account must precede, null for the end
     *
     * @return list<string> the new full sequence
     *
     * @throws InvalidAccountMove when an identifier is not a member, or the anchor is the moved item
     */
    public static function move(array $order, string $accountId, ?string $beforeId): array
    {
        if (!in_array($accountId, $order, true)) {
            throw new InvalidAccountMove('The account is not a member of this group.');
        }
        if (null !== $beforeId && ($beforeId === $accountId || !in_array($beforeId, $order, true))) {
            throw new InvalidAccountMove('The anchor must be another member of this group.');
        }

        $remaining = array_values(array_filter($order, static fn (string $id): bool => $id !== $accountId));
        if (null === $beforeId) {
            return [...$remaining, $accountId];
        }

        $position = array_search($beforeId, $remaining, true);

        return [...array_slice($remaining, 0, (int) $position), $accountId, ...array_slice($remaining, (int) $position)];
    }
}
