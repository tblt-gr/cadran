<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * One dated period as it arrives from a client: the three value shapes and the
 * dates, still as strings. Nothing is coerced here; the parser decides which
 * shape the submitted kind requires and refuses the others.
 */
final readonly class ModelRuleInput
{
    /**
     * @param list<RateBracketInput> $brackets
     */
    public function __construct(
        public string $kind,
        public ?string $amount,
        public ?string $amountAssetCode,
        public ?string $text,
        public ?string $rateApplication,
        public array $brackets,
        public string $validFrom,
        public ?string $validTo,
    ) {
    }
}
