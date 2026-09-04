<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\ProductModelConflict;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads and writes workspace product models across the four tables that hold
 * one: the model, its capabilities, its dated periods and the brackets of its
 * rate scales.
 *
 * Every statement names `workspace_id`, including the ones reaching a child
 * table through its parent: the child rows carry the scope themselves and are
 * joined on the pair, so no query can walk from a model of one workspace into
 * the rows of another.
 *
 * Writing a model rewrites its children rather than diffing them. A model is a
 * small, whole description; comparing row by row would buy nothing and would
 * add a second place for the schedule invariants to be enforced.
 *
 * A write runs inside a transaction, which every use case opens. A rate period
 * and its brackets are several statements of one business operation, and the
 * database asserts the scale is complete at commit; committing between them
 * would refuse a rule whose brackets have not arrived yet.
 */
#[AsAlias(ProductModelRepository::class)]
final readonly class DbalProductModelRepository implements ProductModelRepository
{
    private const string COLUMNS = 'id, workspace_id, name, family, wrapper_kind, yield_kind, default_group_code, valuation_mode, origin, derived_from_product_code, derived_from_model_id, version, created_at, updated_at, archived_at';

    /**
     * trim_scale removes the padding NUMERIC(50,24) adds without altering the
     * value, so a ceiling leaves as `22950` rather than as twenty-four zeros.
     * It is exact: nothing is rounded on the way out.
     */
    private const string RULE_COLUMNS = 'r.id, r.rule_kind, trim_scale(r.amount_value)::text AS amount_value, r.amount_asset, r.text_value, r.rate_application, r.valid_from::text AS valid_from, r.valid_to::text AS valid_to';

    private const string BRACKET_COLUMNS = 'b.position, trim_scale(b.lower_bound)::text AS lower_bound, trim_scale(b.upper_bound)::text AS upper_bound, trim_scale(b.percentage)::text AS percentage';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?ProductModel
    {
        // The model row is read before its children, never after: a model
        // becomes visible together with the children written in the same
        // transaction, so a set read afterwards is complete. Reading the
        // children first would let a model committed in between hydrate with
        // no capability and no period at all.
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_product_models WHERE workspace_id = :workspace_id AND id = :id',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );
        if (false === $row) {
            return null;
        }

        $capabilities = $this->readCapabilities($workspace, [$id]);
        $schedules = $this->readRules($workspace, [$id]);

        return ProductModelRow::hydrate(
            $row,
            $workspace,
            $capabilities[$id] ?? [],
            $schedules[$id] ?? [],
        );
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?ProductModel
    {
        // The row lock is taken before the children are read, so a writer that
        // was already recording a period is waited out and its rules are part
        // of what is read here. Reading them first would return a schedule
        // older than the version stamped on the row, and a caller with no
        // version check — duplication — would copy an incomplete model.
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM account_product_models WHERE workspace_id = :workspace_id AND id = :id FOR UPDATE',
            ['workspace_id' => $workspace->id, 'id' => $id],
        );
        if (false === $row) {
            return null;
        }

        $capabilities = $this->readCapabilities($workspace, [$id]);
        $schedules = $this->readRules($workspace, [$id]);

        return ProductModelRow::hydrate(
            $row,
            $workspace,
            $capabilities[$id] ?? [],
            $schedules[$id] ?? [],
        );
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM account_product_models'
            .' WHERE workspace_id = :workspace_id'.($includeArchived ? '' : ' AND archived_at IS NULL')
            .' ORDER BY normalized_name, id LIMIT :limit OFFSET :offset',
            ['workspace_id' => $workspace->id, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => ProductModelRow::text($row['id'] ?? null), $rows);
        $capabilities = $this->readCapabilities($workspace, $ids);
        $schedules = $this->readRules($workspace, $ids);

        return array_map(
            static fn (array $row): ProductModel => ProductModelRow::hydrate(
                $row,
                $workspace,
                $capabilities[ProductModelRow::text($row['id'] ?? null)] ?? [],
                $schedules[ProductModelRow::text($row['id'] ?? null)] ?? [],
            ),
            $rows,
        );
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived): int
    {
        return (int) ProductModelRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM account_product_models WHERE workspace_id = :workspace_id'
            .($includeArchived ? '' : ' AND archived_at IS NULL'),
            ['workspace_id' => $workspace->id],
        ));
    }

    public function hasActiveName(WorkspaceScope $workspace, string $name, ?string $excludingId = null): bool
    {
        $parameters = ['workspace_id' => $workspace->id, 'name' => $name];
        $excludeClause = '';
        if (null !== $excludingId) {
            $excludeClause = ' AND id <> :excluding_id';
            $parameters['excluding_id'] = $excludingId;
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM account_product_models'
            .' WHERE workspace_id = :workspace_id AND normalized_name = lower(btrim(:name))'
            .' AND archived_at IS NULL'.$excludeClause.' LIMIT 1',
            $parameters,
        );
    }

    public function add(ProductModel $model): void
    {
        $provenance = $model->provenance;

        try {
            $this->connection->insert('account_product_models', [
                'id' => $model->id,
                'workspace_id' => $model->workspace->id,
                'origin' => $provenance->origin->value,
                'derived_from_product_code' => $provenance->systemProductCode?->toString(),
                'derived_from_model_id' => $provenance->sourceModelId,
                ...ProductModelRow::columns($model),
                'created_at' => $model->createdAt->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ProductModelConflict('An active model already uses this name.', previous: $exception);
        }

        $this->writeChildren($model);
    }

    public function update(ProductModel $model, int $expectedVersion): bool
    {
        try {
            $updated = (int) $this->connection->update(
                'account_product_models',
                ProductModelRow::columns($model),
                [
                    'workspace_id' => $model->workspace->id,
                    'id' => $model->id,
                    'version' => $expectedVersion,
                ],
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new ProductModelConflict('An active model already uses this name.', previous: $exception);
        }

        if (1 !== $updated) {
            // The children are left untouched on a stale write: the caller
            // reloads and reapplies, and nothing was half-replaced meanwhile.
            return false;
        }

        // Read before the delete: a period that survives the rewrite keeps the
        // instant it was first recorded, and only a period appearing now is
        // stamped with this write.
        $recordedAt = $this->readRecordedAt($model);

        $this->connection->delete('account_product_model_capabilities', [
            'workspace_id' => $model->workspace->id,
            'model_id' => $model->id,
        ]);
        // The brackets follow their rule through ON DELETE CASCADE.
        $this->connection->delete('account_product_model_rules', [
            'workspace_id' => $model->workspace->id,
            'model_id' => $model->id,
        ]);
        $this->writeChildren($model, $recordedAt);

        return true;
    }

    /**
     * @param array<string, \DateTimeImmutable> $recordedAt
     */
    private function writeChildren(ProductModel $model, array $recordedAt = []): void
    {
        foreach ($model->capabilities->toStrings() as $capability) {
            $this->connection->insert('account_product_model_capabilities', [
                'model_id' => $model->id,
                'workspace_id' => $model->workspace->id,
                'capability_code' => $capability,
            ]);
        }

        foreach ($model->schedule->rules as $rule) {
            $this->connection->insert('account_product_model_rules', [
                'model_id' => $model->id,
                'workspace_id' => $model->workspace->id,
                ...ProductModelRow::ruleColumns($rule, $recordedAt[$rule->id] ?? $model->updatedAt),
            ]);
            $this->writeBrackets($rule, $model);
        }
    }

    private function writeBrackets(ModelRule $rule, ProductModel $model): void
    {
        $scale = $rule->value->scale;
        if (null === $scale) {
            return;
        }

        foreach ($scale->brackets as $index => $bracket) {
            $this->connection->insert('account_product_model_rate_brackets', [
                'rule_id' => $rule->id,
                'workspace_id' => $model->workspace->id,
                'position' => $index + 1,
                'lower_bound' => $bracket->lowerBound->toString(),
                'upper_bound' => $bracket->upperBound?->toString(),
                'percentage' => $bracket->percentage->toString(),
            ]);
        }
    }

    /**
     * Reads normalized capability relations in one round trip. A missing set
     * is not defaulted: ProductCapabilities rejects it as an unusable model.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<string>>
     */
    private function readCapabilities(WorkspaceScope $workspace, array $ids): array
    {
        // No ORDER BY: ProductCapabilities re-sorts into enum-declaration order
        // and the result is keyed by model, so any SQL ordering is discarded.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT model_id, capability_code FROM account_product_model_capabilities'
            .' WHERE workspace_id = :workspace_id AND model_id IN (:ids)',
            ['workspace_id' => $workspace->id, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[ProductModelRow::text($row['model_id'] ?? null)][] = ProductModelRow::text($row['capability_code'] ?? null);
        }

        return $grouped;
    }

    /**
     * Reads every period of the given models, and every bracket of those
     * periods, in one round trip whatever the page size.
     *
     * Periods and brackets are read by the same statement, so they share one
     * snapshot: a rate period committed between two statements could otherwise
     * arrive without the brackets that are its whole value, and hydrating a
     * scale with no bracket fails the read instead of the write. The join is
     * outer because only a rate period has brackets, and it names the workspace
     * on both sides so neither table can be walked into from another one.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<ModelRule>>
     */
    private function readRules(WorkspaceScope $workspace, array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.model_id, '.self::RULE_COLUMNS.', '.self::BRACKET_COLUMNS
            .' FROM account_product_model_rules r'
            // The brackets are bound to the workspace in the join condition, not
            // in the WHERE clause: a workspace predicate on the outer side would
            // discard every period that carries no bracket.
            .' LEFT JOIN account_product_model_rate_brackets b'
            .' ON b.rule_id = r.id AND b.workspace_id = :workspace_id'
            .' WHERE r.workspace_id = :workspace_id AND r.model_id IN (:ids)'
            .' ORDER BY r.model_id, r.rule_kind, r.valid_from, b.position',
            ['workspace_id' => $workspace->id, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        /** @var array<string, array{row: array<string, mixed>, brackets: list<array<string, mixed>>}> $periods */
        $periods = [];
        foreach ($rows as $row) {
            $ruleId = ProductModelRow::text($row['id'] ?? null);
            $periods[$ruleId] ??= ['row' => $row, 'brackets' => []];
            if (null !== ($row['position'] ?? null)) {
                $periods[$ruleId]['brackets'][] = $row;
            }
        }

        $grouped = [];
        foreach ($periods as $period) {
            $grouped[ProductModelRow::text($period['row']['model_id'] ?? null)][] = ProductModelRow::hydrateRule(
                $period['row'],
                $period['brackets'],
            );
        }

        return $grouped;
    }

    /**
     * When each period of a model was first recorded, keyed by period. A write
     * replaces the whole set, so without this the recording instant of every
     * period would be moved forward by the next unrelated write.
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function readRecordedAt(ProductModel $model): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, created_at::text AS created_at FROM account_product_model_rules'
            .' WHERE workspace_id = :workspace_id AND model_id = :model_id',
            ['workspace_id' => $model->workspace->id, 'model_id' => $model->id],
        );

        $recorded = [];
        foreach ($rows as $row) {
            $recorded[ProductModelRow::text($row['id'] ?? null)] = new \DateTimeImmutable(
                ProductModelRow::text($row['created_at'] ?? null),
            );
        }

        return $recorded;
    }
}
