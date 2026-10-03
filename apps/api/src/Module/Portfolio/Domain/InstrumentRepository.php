<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

interface InstrumentRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?Instrument;

    /**
     * @param list<string> $ids
     *
     * @return list<Instrument>
     */
    public function findMany(WorkspaceScope $workspace, array $ids): array;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Instrument;

    public function findByIsin(WorkspaceScope $workspace, Isin $isin, ?string $excludingId = null): ?Instrument;

    /** @return list<Instrument> */
    public function duplicateCandidates(WorkspaceScope $workspace, ?Ticker $ticker, string $normalizedName, ?string $excludingId, int $limit): array;

    /** @return list<Instrument> */
    public function list(WorkspaceScope $workspace, ?InstrumentStatus $status, ?string $query, ?AssetCode $currency, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, ?InstrumentStatus $status, ?string $query, ?AssetCode $currency): int;

    /** Serializes identifier and capacity checks for every workspace write. */
    public function lockWorkspace(WorkspaceScope $workspace): void;

    public function countAll(WorkspaceScope $workspace): int;

    public function add(Instrument $instrument): void;

    public function update(Instrument $instrument, int $expectedVersion): bool;
}
