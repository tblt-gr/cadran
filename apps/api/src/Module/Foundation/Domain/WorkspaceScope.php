<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * The single workspace an operation is allowed to touch.
 *
 * Every business record belongs to a workspace, and every query that reads or
 * writes one must constrain it. Passing that constraint as a dedicated type
 * rather than a bare string is what makes the rule checkable: a repository
 * method that accepts a WorkspaceScope cannot accidentally receive a bare
 * client-supplied identifier. Production construction is additionally limited
 * to membership resolution and initial provisioning by the architecture guard.
 */
final readonly class WorkspaceScope implements \Stringable
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    private function __construct(public string $id)
    {
    }

    /**
     * Security-sensitive hydration point. New production call sites must be
     * reviewed and explicitly trusted by the workspace-scope architecture guard.
     */
    public static function fromString(string $id): self
    {
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            throw new \InvalidArgumentException('A workspace scope is a UUID.');
        }

        // Lowercase is the canonical form: two scopes that differ only in case
        // name the same workspace and must compare equal.
        return new self(strtolower($id));
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
