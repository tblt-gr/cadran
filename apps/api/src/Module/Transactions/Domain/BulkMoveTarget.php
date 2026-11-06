<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

/** What a bulk move needs to know about an account, without depending on the Accounts module. */
final readonly class BulkMoveTarget
{
    public function __construct(
        public string $accountId,
        public string $label,
        public string $assetCode,
        public \DateTimeImmutable $openedOn,
        public ?\DateTimeImmutable $closedOn,
        public bool $portfolioValued,
    ) {
    }

    public function isOpenOn(\DateTimeImmutable $day): bool
    {
        $iso = $day->format('Y-m-d');

        return $this->openedOn->format('Y-m-d') <= $iso
            && (null === $this->closedOn || $this->closedOn->format('Y-m-d') >= $iso);
    }
}
