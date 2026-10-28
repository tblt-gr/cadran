<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * The frozen weight of one account in a month's net worth: its signed end value, the way the net
 * worth summed it. A closed month keeps these so its group columns can follow the current tree
 * without any account value being recomputed.
 */
final readonly class AccountWeight
{
    /** @param ?string $signedValue canonical decimal, negative for a liability; null when not valued */
    public function __construct(
        public string $accountId,
        public ?string $signedValue,
        public ?string $assetCode,
        public bool $includeInNetWorth,
    ) {
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws \UnexpectedValueException when the payload is not one {@see toPayload()} wrote
     */
    public static function fromPayload(array $payload): self
    {
        $accountId = $payload['accountId'] ?? null;
        $value = $payload['signedValue'] ?? null;
        $asset = $payload['assetCode'] ?? null;
        $included = $payload['includeInNetWorth'] ?? null;
        if (!is_string($accountId) || '' === $accountId || !is_bool($included)) {
            throw new \UnexpectedValueException('A snapshot account weight names its account and its inclusion.');
        }
        if ((null !== $value && !is_string($value)) || (null !== $asset && !is_string($asset)) || (null === $value) !== (null === $asset)) {
            throw new \UnexpectedValueException('A snapshot account weight carries a value and its asset together, or neither.');
        }

        try {
            if (null !== $value) {
                DecimalValue::fromString($value);
                AssetCode::fromString($asset ?? '');
            }
        } catch (\InvalidArgumentException $exception) {
            throw new \UnexpectedValueException('A snapshot account weight holds an exact decimal and an asset code.', previous: $exception);
        }

        return new self($accountId, $value, $asset, $included);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'accountId' => $this->accountId,
            'signedValue' => $this->signedValue,
            'assetCode' => $this->assetCode,
            'includeInNetWorth' => $this->includeInNetWorth,
        ];
    }
}
