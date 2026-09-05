<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;

/**
 * The asset reference behind each published figure, read once per code.
 *
 * A net-worth answer rounds many amounts for display and most of them share
 * one asset; without this the catalogue would be queried once per account.
 */
final class NetWorthAssetReferences
{
    /** @var array<string, ?Asset> */
    private array $known = [];

    public function __construct(private readonly AssetCatalog $assets)
    {
    }

    public function for(?AssetCode $code): ?Asset
    {
        if (null === $code) {
            return null;
        }

        $key = $code->toString();
        if (!array_key_exists($key, $this->known)) {
            $this->known[$key] = $this->assets->findByCode($code);
        }

        return $this->known[$key];
    }
}
