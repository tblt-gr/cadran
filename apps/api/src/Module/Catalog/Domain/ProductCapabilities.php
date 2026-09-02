<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * The validated, deterministic capability set of one product model.
 *
 * Dependency validation prevents a capability from exposing a use case whose
 * underlying state or operation the product cannot support.
 */
final readonly class ProductCapabilities
{
    /** @var list<ProductCapability> */
    private array $capabilities;

    private function __construct(ProductCapability ...$capabilities)
    {
        if ([] === $capabilities) {
            throw new InvalidCatalogEntry('A product declares at least one capability.');
        }

        $seen = [];
        foreach ($capabilities as $capability) {
            if (isset($seen[$capability->value])) {
                throw new InvalidCatalogEntry(sprintf('%s is declared more than once.', $capability->value));
            }

            $seen[$capability->value] = true;
        }

        foreach (self::dependencies() as $capability => $requirements) {
            if (!isset($seen[$capability])) {
                continue;
            }

            foreach ($requirements as $required) {
                if (!isset($seen[$required])) {
                    throw new InvalidCatalogEntry(sprintf('%s requires %s.', $capability, $required));
                }
            }
        }

        $ordered = [];
        foreach (ProductCapability::cases() as $known) {
            if (isset($seen[$known->value])) {
                $ordered[] = $known;
            }
        }

        $this->capabilities = $ordered;
    }

    public static function of(ProductCapability ...$capabilities): self
    {
        return new self(...$capabilities);
    }

    /**
     * @param list<string> $capabilities
     */
    public static function fromStrings(array $capabilities): self
    {
        $known = [];
        foreach ($capabilities as $capability) {
            try {
                $known[] = ProductCapability::from($capability);
            } catch (\ValueError) {
                throw new InvalidCatalogEntry(sprintf('%s is not a supported product capability.', $capability));
            }
        }

        return new self(...$known);
    }

    /** @return list<ProductCapability> */
    public function all(): array
    {
        return $this->capabilities;
    }

    /** @return list<string> */
    public function toStrings(): array
    {
        return array_map(
            static fn (ProductCapability $capability): string => $capability->value,
            $this->capabilities,
        );
    }

    public function contains(ProductCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /** @return array<string, list<string>> */
    private static function dependencies(): array
    {
        return [
            ProductCapability::SUPPORTS_INTEREST->value => [
                ProductCapability::SUPPORTS_BALANCE->value,
            ],
            ProductCapability::SUPPORTS_HOLDINGS->value => [
                ProductCapability::SUPPORTS_BALANCE->value,
            ],
            ProductCapability::SUPPORTS_TRADES->value => [
                ProductCapability::SUPPORTS_HOLDINGS->value,
                ProductCapability::SUPPORTS_TRANSACTIONS->value,
            ],
            ProductCapability::SUPPORTS_ARBITRAGE->value => [
                ProductCapability::SUPPORTS_HOLDINGS->value,
                ProductCapability::SUPPORTS_TRANSACTIONS->value,
            ],
            ProductCapability::SUPPORTS_CONTRIBUTIONS->value => [
                ProductCapability::SUPPORTS_TRANSACTIONS->value,
            ],
            ProductCapability::SUPPORTS_FEES->value => [
                ProductCapability::SUPPORTS_TRANSACTIONS->value,
            ],
            ProductCapability::SUPPORTS_TAX_TRACKING->value => [
                ProductCapability::SUPPORTS_TRANSACTIONS->value,
            ],
            ProductCapability::SUPPORTS_LIABILITY->value => [
                ProductCapability::SUPPORTS_BALANCE->value,
            ],
        ];
    }
}
