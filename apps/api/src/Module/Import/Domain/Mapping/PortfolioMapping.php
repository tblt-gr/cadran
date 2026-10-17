<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

final readonly class PortfolioMapping
{
    /**
     * @param array<string, ?InstrumentMapping> $instruments by normalized column header, null while unmapped
     */
    public function __construct(
        public ?string $account,
        private array $instruments,
    ) {
    }

    public function instrument(string $headerKey): ?InstrumentMapping
    {
        return $this->instruments[$headerKey] ?? null;
    }

    public function mentionsInstrument(string $headerKey): bool
    {
        return array_key_exists($headerKey, $this->instruments);
    }
}
