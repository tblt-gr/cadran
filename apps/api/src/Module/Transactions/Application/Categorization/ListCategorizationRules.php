<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;

final readonly class ListCategorizationRules
{
    public function __construct(private CallerWorkspaceContext $caller, private CategorizationRuleRepository $rules, private PresentCategorizationRule $presenter)
    {
    }

    /** @return array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int} */
    public function __invoke(bool $includeArchived, int $page, int $pageSize): array
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw new InvalidCategorizationRuleInput();
        }
        $workspace = $this->caller->resolveContext()->workspace;

        return ['items' => $this->presenter->many($this->rules->list($workspace, $includeArchived, $pageSize, ($page - 1) * $pageSize)), 'page' => $page, 'pageSize' => $pageSize, 'total' => $this->rules->count($workspace, $includeArchived)];
    }
}
