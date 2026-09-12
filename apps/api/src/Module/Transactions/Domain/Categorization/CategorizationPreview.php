<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

use App\Module\Foundation\Domain\WorkspaceScope;

/** A produced preview a bulk run may be applied from once, before it expires. */
final readonly class CategorizationPreview
{
    public const string LIFETIME = '+15 minutes';

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $token,
        public ?string $ruleId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
    ) {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $token) || $to < $from || $expiresAt <= $createdAt) {
            throw new InvalidCategorizationRule('A preview carries a SHA-256 token, an ordered period and a future expiry.');
        }
    }
}
