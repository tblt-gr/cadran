<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

final class PositionReplayFailed extends \DomainException
{
    /** @param array{operationId: string, tradeDate: string, instrumentId: string} $extensions */
    public function __construct(public readonly string $problemCode, public readonly array $extensions)
    {
        parent::__construct($problemCode);
    }
}
