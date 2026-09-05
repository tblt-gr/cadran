<?php

declare(strict_types=1);

namespace App\Module\Categories\Infrastructure\Persistence;

use App\Module\Categories\Application\CategoryConflict;
use App\Module\Categories\Domain\CategoryReplacement;
use App\Module\Categories\Domain\CategoryReplacementRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CategoryReplacementRepository::class)]
final readonly class DbalCategoryReplacementRepository implements CategoryReplacementRepository
{
    private const string COLUMNS = 'id, workspace_id, source_category_id, target_category_id, kind, effective_from, created_at';

    public function __construct(private Connection $connection)
    {
    }

    public function findBySource(WorkspaceScope $workspace, string $sourceCategoryId): ?CategoryReplacement
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM category_replacements'
            .' WHERE workspace_id = :workspace_id AND source_category_id = :source_category_id',
            ['workspace_id' => $workspace->id, 'source_category_id' => $sourceCategoryId],
        );

        return false === $row ? null : CategoryReplacementRow::hydrate($row, $workspace);
    }

    public function findByTarget(WorkspaceScope $workspace, string $targetCategoryId, bool $lock = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM category_replacements'
            .' WHERE workspace_id = :workspace_id AND target_category_id = :target_category_id'
            .' ORDER BY id'.($lock ? ' FOR UPDATE' : ''),
            ['workspace_id' => $workspace->id, 'target_category_id' => $targetCategoryId],
        );

        return array_map(
            static fn (array $row): CategoryReplacement => CategoryReplacementRow::hydrate($row, $workspace),
            $rows,
        );
    }

    public function findBySources(WorkspaceScope $workspace, array $sourceCategoryIds): array
    {
        if ([] === $sourceCategoryIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM category_replacements'
            .' WHERE workspace_id = :workspace_id AND source_category_id IN (:ids)',
            ['workspace_id' => $workspace->id, 'ids' => $sourceCategoryIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $replacements = [];
        foreach ($rows as $row) {
            $replacement = CategoryReplacementRow::hydrate($row, $workspace);
            $replacements[$replacement->sourceCategoryId] = $replacement;
        }

        return $replacements;
    }

    public function chainFrom(WorkspaceScope $workspace, string $sourceCategoryId): array
    {
        $chain = [];
        $cursor = $sourceCategoryId;
        // The database trigger applies the same ceiling; walking further here would only
        // trade a clear refusal for a statement that fails deeper in the request.
        for ($step = 0; $step < CategoryReplacementRepository::MAX_CHAIN_LENGTH; ++$step) {
            $next = $this->connection->fetchOne(
                'SELECT target_category_id FROM category_replacements'
                .' WHERE workspace_id = :workspace_id AND source_category_id = :source_category_id',
                ['workspace_id' => $workspace->id, 'source_category_id' => $cursor],
            );
            if (false === $next) {
                return $chain;
            }

            $cursor = CategoryRow::text($next);
            $chain[] = $cursor;
            if ($cursor === $sourceCategoryId) {
                return $chain;
            }
        }

        return $chain;
    }

    public function add(CategoryReplacement $replacement): void
    {
        try {
            $this->connection->insert('category_replacements', [
                'id' => $replacement->id,
                'workspace_id' => $replacement->workspace->id,
                'source_category_id' => $replacement->sourceCategoryId,
                'target_category_id' => $replacement->targetCategoryId,
                'kind' => $replacement->kind->value,
                'effective_from' => $replacement->effectiveFrom?->format('Y-m-d'),
                'created_at' => $replacement->createdAt->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new CategoryConflict('This category is already redirected to another one.', previous: $exception);
        }
    }

    public function repoint(CategoryReplacement $replacement): void
    {
        $this->connection->update(
            'category_replacements',
            ['target_category_id' => $replacement->targetCategoryId],
            ['workspace_id' => $replacement->workspace->id, 'id' => $replacement->id],
        );
    }
}
