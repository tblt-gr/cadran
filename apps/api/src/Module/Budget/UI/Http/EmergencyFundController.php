<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Budget\Application\EmergencyFund\EmergencyFundConflict;
use App\Module\Budget\Application\EmergencyFund\EmergencyFundCoverageView;
use App\Module\Budget\Application\EmergencyFund\EmergencyFundInputError;
use App\Module\Budget\Application\EmergencyFund\EmergencyFundPolicyInput;
use App\Module\Budget\Application\EmergencyFund\EmergencyFundPolicyView;
use App\Module\Budget\Application\EmergencyFund\EmergencyFundScopeTooLarge;
use App\Module\Budget\Application\EmergencyFund\ReadEmergencyFundCoverage;
use App\Module\Budget\Application\EmergencyFund\ReadEmergencyFundPolicy;
use App\Module\Budget\Application\EmergencyFund\UpdateEmergencyFundPolicy;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EmergencyFundController
{
    private const array POLICY_FIELDS = ['essentialMode', 'essentialCategoryIds', 'referenceMode', 'trailingMonths', 'targetMonths', 'version'];

    public function __construct(private BudgetHttpEnvelope $envelope)
    {
    }

    // Ranked above `/goals/{id}`, which would otherwise answer this path as an unknown goal.
    #[Route('/api/v1/goals/emergency-fund', name: 'api_v1_goals_emergency_fund', methods: ['GET'], priority: 10)]
    public function coverage(Request $request, ReadEmergencyFundCoverage $read): Response
    {
        try {
            return $this->envelope->json(self::coverageRepresentation($read($request->query->get('asOf'))));
        } catch (EmergencyFundInputError $error) {
            return $this->problem('future_emergency_fund_as_of' === $error->errorCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST, $error->errorCode);
        } catch (EmergencyFundScopeTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'emergency_fund_scope_too_large');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'emergency_fund_forbidden');
        }
    }

    #[Route('/api/v1/goals/emergency-fund/policy', name: 'api_v1_goals_emergency_fund_policy_read', methods: ['GET'], priority: 10)]
    public function readPolicy(ReadEmergencyFundPolicy $read): Response
    {
        try {
            return $this->envelope->json(self::policyRepresentation($read()));
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'emergency_fund_forbidden');
        }
    }

    #[Route('/api/v1/goals/emergency-fund/policy', name: 'api_v1_goals_emergency_fund_policy_update', methods: ['PUT'], priority: 10)]
    public function updatePolicy(Request $request, UpdateEmergencyFundPolicy $update): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = $update(self::policyInput($body));
        } catch (EmergencyFundInputError $error) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $error->errorCode);
        } catch (EmergencyFundConflict $error) {
            return $this->problem(Response::HTTP_CONFLICT, $error->errorCode);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'emergency_fund_forbidden');
        }

        return $this->envelope->json(self::policyRepresentation($result));
    }

    /** @param array<mixed> $body */
    private static function policyInput(array $body): EmergencyFundPolicyInput
    {
        $malformed = new EmergencyFundInputError('invalid_emergency_fund_policy', 'The policy body is malformed.');
        foreach (array_keys($body) as $key) {
            if (!in_array($key, self::POLICY_FIELDS, true)) {
                throw $malformed;
            }
        }
        foreach (self::POLICY_FIELDS as $key) {
            if (!array_key_exists($key, $body)) {
                throw $malformed;
            }
        }
        $ids = $body['essentialCategoryIds'];
        if (!is_array($ids) || !array_is_list($ids)) {
            throw $malformed;
        }
        $categoryIds = [];
        foreach ($ids as $id) {
            $categoryIds[] = is_string($id) ? $id : throw $malformed;
        }
        $trailing = $body['trailingMonths'];
        if (!is_string($body['essentialMode']) || !is_string($body['referenceMode']) || !is_string($body['targetMonths']) || !is_int($body['version']) || (null !== $trailing && !is_int($trailing))) {
            throw $malformed;
        }

        return new EmergencyFundPolicyInput($body['essentialMode'], $categoryIds, $body['referenceMode'], $trailing, $body['targetMonths'], $body['version']);
    }

    /** @return array<string, mixed> */
    private static function policyRepresentation(EmergencyFundPolicyView $view): array
    {
        return [
            'essentialMode' => $view->essentialMode,
            'essentialCategories' => $view->essentialCategories,
            'referenceMode' => $view->referenceMode,
            'trailingMonths' => $view->trailingMonths,
            'targetMonths' => $view->targetMonths,
            'version' => $view->version,
        ];
    }

    /** @return array<string, mixed> */
    private static function coverageRepresentation(EmergencyFundCoverageView $view): array
    {
        return [
            'asOf' => $view->asOf,
            'liquidAssets' => [
                'value' => $view->liquidAssets,
                'assetCode' => $view->liquidAssetCode,
                'reason' => $view->liquidReason,
                'accounts' => $view->accounts,
            ],
            'reference' => [
                'start' => $view->referenceStart,
                'end' => $view->referenceEnd,
                'usedMonths' => $view->usedMonths,
                'expectedMonths' => $view->expectedMonths,
                'excludedMonths' => $view->excludedMonths,
                'average' => $view->average,
                'averageReason' => $view->averageReason,
                'assetCode' => $view->referenceAssetCode,
                'months' => $view->months,
            ],
            'targetMonths' => $view->targetMonths,
            'requiredAmount' => $view->requiredAmount,
            'gap' => $view->gap,
            'coveredMonths' => $view->coveredMonths,
            'coveredMonthsDisplay' => $view->coveredMonthsDisplay,
            'reason' => $view->reason,
            'quality' => $view->quality,
        ];
    }

    private function problem(int $status, string $code): Response
    {
        return $this->envelope->problem($status, 'api.problem.goal_error', '/problems/'.$code, ['code' => $code]);
    }
}
