<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * One dated rule a workspace declares, as it arrives from a client: the three
 * value shapes and the dates, still uncoerced. A reusable product model and a
 * per-account override submit the same shape, because they state the same
 * thing about different scopes.
 *
 * Nothing is coerced here; {@see DeclaredRuleParser} decides which shape the
 * submitted kind requires and refuses the others.
 */
final readonly class DeclaredRuleInput
{
    /**
     * @param list<RateBracketInput> $brackets
     */
    public function __construct(
        public string $kind,
        public mixed $amount,
        public mixed $amountAssetCode,
        public ?string $text,
        public ?string $rateApplication,
        public array $brackets,
        public string $validFrom,
        public ?string $validTo,
        public string $amountPointer = '/amount',
        public string $amountAssetCodePointer = '/amountAssetCode',
    ) {
    }
}
