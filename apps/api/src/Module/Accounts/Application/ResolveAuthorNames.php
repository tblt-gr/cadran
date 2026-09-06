<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Identity\Application\AuthenticationUserRepository;

/**
 * Turns override author identifiers into display names. The UUID stays on
 * the wire; the name is what a history table can show without a second hop.
 */
final readonly class ResolveAuthorNames
{
    public function __construct(private AuthenticationUserRepository $users)
    {
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function forIds(array $ids): array
    {
        $names = [];
        foreach (array_unique($ids) as $id) {
            $user = $this->users->findById($id);
            if (null !== $user) {
                $names[$id] = $user->displayName;
            }
        }

        return $names;
    }
}
