<?php

declare(strict_types=1);

namespace App\Module\Reference\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Reference\Domain\Asset;

/**
 * Read access to the system asset reference.
 *
 * The catalogue is one of the few global, read-only tables: an asset belongs
 * to no workspace, and no request can create, alter or archive one. It is
 * therefore not filtered by workspace, and it is written only by a migration.
 */
interface AssetCatalog
{
    public function findByCode(AssetCode $code): ?Asset;

    /**
     * Returns at most $limit assets from $offset, in a stable order.
     *
     * @return list<Asset>
     */
    public function readPage(int $limit, int $offset): array;

    public function count(): int;
}
