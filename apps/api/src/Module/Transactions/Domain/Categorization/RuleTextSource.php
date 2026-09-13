<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

enum RuleTextSource: string
{
    case RAW_LABEL = 'RAW_LABEL';
    case NORMALIZED_LABEL = 'NORMALIZED_LABEL';
    case COUNTERPARTY = 'COUNTERPARTY';

    public function read(CategorizationSubject $subject): ?string
    {
        return match ($this) {
            self::RAW_LABEL => $subject->rawLabel,
            self::NORMALIZED_LABEL => $subject->normalizedLabel(),
            self::COUNTERPARTY => $subject->counterparty,
        };
    }
}
