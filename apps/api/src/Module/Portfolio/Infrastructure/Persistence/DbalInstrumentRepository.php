<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\InstrumentIsinExists;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\Isin;
use App\Module\Portfolio\Domain\Ticker;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(InstrumentRepository::class)]
final readonly class DbalInstrumentRepository implements InstrumentRepository
{
    private const string COLUMNS = 'id, workspace_id, name, normalized_name, isin, ticker, venue_mic, instrument_type, asset_class, region, currency_code, quantity_precision, price_precision, source, reviewed_at, reviewed_by, status, used_at, created_at, created_by, updated_at, version';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?Instrument
    {
        return $this->one($workspace, 'id = :id', ['workspace_id' => $workspace->id, 'id' => $id]);
    }

    public function findMany(WorkspaceScope $workspace, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_instruments WHERE workspace_id = :workspace_id AND id IN (:ids)',
            ['workspace_id' => $workspace->id, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $row): Instrument => InstrumentRow::hydrate($row, $workspace), $rows);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Instrument
    {
        return $this->one($workspace, 'id = :id', ['workspace_id' => $workspace->id, 'id' => $id], true);
    }

    public function findByIsin(WorkspaceScope $workspace, Isin $isin, ?string $excludingId = null): ?Instrument
    {
        $where = 'isin = :isin';
        $parameters = ['workspace_id' => $workspace->id, 'isin' => $isin->toString()];
        if (null !== $excludingId) {
            $where .= ' AND id <> :excluding_id';
            $parameters['excluding_id'] = $excludingId;
        }

        return $this->one($workspace, $where, $parameters);
    }

    public function duplicateCandidates(WorkspaceScope $workspace, ?Ticker $ticker, string $normalizedName, ?string $excludingId, int $limit): array
    {
        $matches = ['normalized_name = :normalized_name'];
        $parameters = ['workspace_id' => $workspace->id, 'normalized_name' => $normalizedName, 'limit' => $limit];
        if (null !== $ticker) {
            $matches[] = 'upper(ticker) = :ticker';
            $parameters['ticker'] = $ticker->toString();
        }
        $exclude = '';
        if (null !== $excludingId) {
            $exclude = ' AND id <> :excluding_id';
            $parameters['excluding_id'] = $excludingId;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_instruments WHERE workspace_id = :workspace_id AND ('.implode(' OR ', $matches).')'.$exclude.' ORDER BY normalized_name, id LIMIT :limit',
            $parameters,
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): Instrument => InstrumentRow::hydrate($row, $workspace), $rows);
    }

    public function list(WorkspaceScope $workspace, ?InstrumentStatus $status, ?string $query, ?AssetCode $currency, int $limit, int $offset, ?\App\Module\Portfolio\Domain\InstrumentType $instrumentType = null): array
    {
        [$where, $parameters] = self::filters($workspace, $status, $query, $currency, $instrumentType);
        $parameters['limit'] = $limit;
        $parameters['offset'] = $offset;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_instruments WHERE workspace_id = :workspace_id AND ('.$where.') ORDER BY normalized_name, id LIMIT :limit OFFSET :offset',
            $parameters,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): Instrument => InstrumentRow::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace, ?InstrumentStatus $status, ?string $query, ?AssetCode $currency, ?\App\Module\Portfolio\Domain\InstrumentType $instrumentType = null): int
    {
        [$where, $parameters] = self::filters($workspace, $status, $query, $currency, $instrumentType);

        return InstrumentRow::integer($this->connection->fetchOne('SELECT count(*) FROM portfolio_instruments WHERE workspace_id = :workspace_id AND ('.$where.')', $parameters));
    }

    public function lockWorkspace(WorkspaceScope $workspace): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:workspace_id, 0))', ['workspace_id' => $workspace->id]);
    }

    public function countAll(WorkspaceScope $workspace): int
    {
        return InstrumentRow::integer($this->connection->fetchOne('SELECT count(*) FROM portfolio_instruments WHERE workspace_id = :workspace_id', ['workspace_id' => $workspace->id]));
    }

    public function add(Instrument $instrument): void
    {
        try {
            $this->connection->insert('portfolio_instruments', [
                'id' => $instrument->id,
                'workspace_id' => $instrument->workspace->id,
                ...InstrumentRow::columns($instrument),
                'created_at' => $instrument->createdAt->format('Y-m-d H:i:s.uP'),
                'created_by' => $instrument->createdBy,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new InstrumentIsinExists(null, previous: $exception);
        }
    }

    public function update(Instrument $instrument, int $expectedVersion): bool
    {
        try {
            return 1 === (int) $this->connection->update(
                'portfolio_instruments',
                InstrumentRow::columns($instrument),
                ['workspace_id' => $instrument->workspace->id, 'id' => $instrument->id, 'version' => $expectedVersion],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new InstrumentIsinExists(null, previous: $exception);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function one(WorkspaceScope $workspace, string $where, array $parameters, bool $forUpdate = false): ?Instrument
    {
        $row = $this->connection->fetchAssociative('SELECT '.self::COLUMNS.' FROM portfolio_instruments WHERE workspace_id = :workspace_id AND ('.$where.')'.($forUpdate ? ' FOR UPDATE' : ''), $parameters);

        return false === $row ? null : InstrumentRow::hydrate($row, $workspace);
    }

    /** @return array{string, array<string, mixed>} */
    private static function filters(WorkspaceScope $workspace, ?InstrumentStatus $status, ?string $query, ?AssetCode $currency, ?\App\Module\Portfolio\Domain\InstrumentType $instrumentType): array
    {
        $conditions = ['1 = 1'];
        $parameters = ['workspace_id' => $workspace->id];
        if (null !== $status) {
            $conditions[] = 'status = :status';
            $parameters['status'] = $status->value;
        }
        if (null !== $query && '' !== $query) {
            $conditions[] = "(position(lower(:query) in normalized_name) > 0 OR upper(ticker) LIKE upper(:prefix) ESCAPE '\\' OR upper(isin) LIKE upper(:prefix) ESCAPE '\\')";
            $parameters['query'] = $query;
            // The query is user text: its LIKE metacharacters must match literally.
            $parameters['prefix'] = addcslashes($query, '%_\\').'%';
        }
        if (null !== $instrumentType) {
            $conditions[] = 'instrument_type = :instrument_type';
            $parameters['instrument_type'] = $instrumentType->value;
        }
        if (null !== $currency) {
            $conditions[] = 'currency_code = :currency_code';
            $parameters['currency_code'] = $currency->toString();
        }

        return [implode(' AND ', $conditions), $parameters];
    }
}
