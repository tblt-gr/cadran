<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\Goals\BulkEditGoals;
use App\Module\Budget\Application\Goals\BulkGoalItemView;
use App\Module\Budget\Application\Goals\GoalBulkConflict;
use App\Module\Budget\Application\Goals\GoalInputError;
use App\Module\Budget\Application\Goals\GoalNotFound;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GoalBulkController
{
    public function __construct(private BudgetHttpEnvelope $envelope, private BulkEditGoals $bulk)
    {
    }

    #[Route('/api/v1/goals/bulk-preview', name: 'api_v1_goals_bulk_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        return $this->execute($request, false);
    }

    #[Route('/api/v1/goals/bulk-edit', name: 'api_v1_goals_bulk_edit', methods: ['POST'])]
    public function edit(Request $request): Response
    {
        return $this->execute($request, true);
    }

    private function execute(Request $request, bool $save): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = ($this->bulk)(GoalBulkRequest::from($body), $save);

            return $this->envelope->json([...$result, 'items' => array_map(self::item(...), $result['items'])]);
        } catch (GoalInputError $error) {
            return $this->problem(422, $error->errorCode, $error->details);
        } catch (GoalBulkConflict $error) {
            return $this->problem(409, $error->errorCode, ['goalId' => $error->goalId, 'field' => $error->field]);
        } catch (GoalNotFound) {
            return $this->problem(404, 'goal_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, 'goal_forbidden');
        }
    }

    /** @return array<string, mixed> */
    private static function item(BulkGoalItemView $item): array
    {
        return [
            'goalId' => $item->goalId, 'label' => $item->label, 'expectedVersion' => $item->expectedVersion,
            'valueType' => $item->valueType, 'assetCode' => $item->assetCode,
            'before' => $item->before, 'after' => $item->after,
            'firstAffectedMonth' => $item->firstAffectedMonth, 'lastAffectedMonth' => $item->lastAffectedMonth,
            'laterRevisions' => $item->laterRevisions, 'overrides' => $item->overrides,
            'changed' => $item->changed, 'conflict' => $item->conflict,
        ];
    }

    /** @param array<string, string> $details */
    private function problem(int $status, string $code, array $details = []): Response
    {
        return $this->envelope->problem($status, 'api.problem.goal_error', '/problems/'.$code, ['code' => $code, ...$details]);
    }
}
