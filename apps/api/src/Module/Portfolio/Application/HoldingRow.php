<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/**
 * One instrument across the accounts, before it is ordered and weighed.
 *
 * @phpstan-type Share array{ratio: ?string, percentDisplay: ?string, reason: ?string}
 * @phpstan-type AccountLine array{accountId: string, label: string, quantity: string, value: ?string, costBasis: ?string, unrealizedGain: ?string, performance: ?string, valuedOn: string, priceDate: ?string, priceQuality: string}
 */
final readonly class HoldingRow
{
    /**
     * @param array{id: string, name: string, isin: ?string, ticker: ?string, status: string, quantityPrecision: int} $instrument
     * @param array{price: string, priceDate: string, priceQuality: string}|null                                      $lastPrice
     * @param Share                                                                                                   $share
     * @param list<AccountLine>                                                                                       $accounts
     */
    public function __construct(
        public array $instrument,
        public string $instrumentType,
        public ?string $assetClass,
        public ?string $region,
        public string $quantity,
        public ?string $value,
        public ?string $costBasis,
        public ?string $unrealizedGain,
        public ?string $performance,
        public ?string $averageUnitCost,
        public ?string $reason,
        public ?array $lastPrice,
        public array $share,
        public array $accounts,
    ) {
    }

    /** @param Share $share */
    public function withShare(array $share): self
    {
        return new self($this->instrument, $this->instrumentType, $this->assetClass, $this->region, $this->quantity, $this->value, $this->costBasis,
            $this->unrealizedGain, $this->performance, $this->averageUnitCost, $this->reason, $this->lastPrice, $share, $this->accounts);
    }

    /** Accounts in several assets cannot be added: every amount is withheld, the quantity stays. */
    public function withoutAmounts(): self
    {
        $accounts = array_map(static fn (array $line): array => [...$line, 'value' => null, 'costBasis' => null, 'unrealizedGain' => null, 'performance' => null], $this->accounts);

        return new self($this->instrument, $this->instrumentType, $this->assetClass, $this->region, $this->quantity, null, null,
            null, null, null, 'MIXED_ASSETS', $this->lastPrice, $this->share, $accounts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['instrument' => $this->instrument, 'instrumentType' => $this->instrumentType, 'quantity' => $this->quantity, 'value' => $this->value,
            'costBasis' => $this->costBasis, 'unrealizedGain' => $this->unrealizedGain, 'performance' => $this->performance,
            'averageUnitCost' => $this->averageUnitCost, 'reason' => $this->reason, 'lastPrice' => $this->lastPrice, 'share' => $this->share,
            'accounts' => $this->accounts];
    }
}
