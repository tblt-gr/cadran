<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetCode;

final readonly class MovementWindow
{
    public function __construct(
        public string $accountId,
        public \DateTimeImmutable $afterExclusive,
        public \DateTimeImmutable $upToInclusive,
        public AssetCode $assetCode,
    ) {
        if ($afterExclusive >= $upToInclusive) {
            throw new \InvalidArgumentException('A movement window must follow its anchor.');
        }
    }

    public function key(): string
    {
        return $this->accountId.'|'.$this->afterExclusive->format('Y-m-d').'|'.$this->upToInclusive->format('Y-m-d');
    }
}
