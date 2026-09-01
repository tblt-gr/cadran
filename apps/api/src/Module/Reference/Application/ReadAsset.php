<?php

declare(strict_types=1);

namespace App\Module\Reference\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Reference\Domain\Asset;

/**
 * Reads one asset of the system reference by its code.
 */
final readonly class ReadAsset
{
    public function __construct(private AssetCatalog $catalog)
    {
    }

    public function __invoke(string $code): Asset
    {
        try {
            $assetCode = AssetCode::fromString($code);
        } catch (\InvalidArgumentException) {
            // A code that cannot exist and a code that does not exist get the
            // same answer, so the endpoint keeps one contract for "no such
            // asset" whatever the caller sent.
            throw new AssetNotFound('No asset carries this code.');
        }

        $asset = $this->catalog->findByCode($assetCode);
        if (null === $asset) {
            throw new AssetNotFound('No asset carries this code.');
        }

        return $asset;
    }
}
