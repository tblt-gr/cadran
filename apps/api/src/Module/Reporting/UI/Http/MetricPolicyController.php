<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Reporting\Application\ActivateMetricPolicy;
use App\Module\Reporting\Application\ActivateMetricPolicyInput;
use App\Module\Reporting\Application\CreateMetricPolicy;
use App\Module\Reporting\Application\CreateMetricPolicyInput;
use App\Module\Reporting\Application\InvalidMetricPolicyInput;
use App\Module\Reporting\Application\MetricPolicyActivationLimit;
use App\Module\Reporting\Application\MetricPolicyAlreadyActive;
use App\Module\Reporting\Application\MetricPolicyDefinitionExists;
use App\Module\Reporting\Application\MetricPolicyVersionLimit;
use App\Module\Reporting\Application\ReadMetricPolicies;
use App\Module\Reporting\Application\StaleActiveMetricPolicy;
use App\Module\Reporting\Application\UnknownMetricPolicyVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Lists, creates and activates the workspace metric policy versions. No version is ever edited or deleted. */
final readonly class MetricPolicyController
{
    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    #[Route('/api/v1/reports/metric-policies', name: 'api_v1_reports_metric_policies_list', methods: ['GET'])]
    public function list(ReadMetricPolicies $read): Response
    {
        try {
            $catalog = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return new JsonResponse(MetricPolicyRepresentation::catalog($catalog), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/reports/metric-policies', name: 'api_v1_reports_metric_policies_create', methods: ['POST'])]
    public function create(Request $request, CreateMetricPolicy $create): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Small, 'api.problem.invalid_metric_policy');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['label', 'cashExcludedAccountKinds']);
            // The use case bounds the selection against the account kinds.
            $policy = $create(new CreateMetricPolicyInput($payload->string('label'), $payload->stringList('cashExcludedAccountKinds', \PHP_INT_MAX)));
        } catch (InvalidMetricPolicyInput|MalformedPayload) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_metric_policy');
        } catch (MetricPolicyDefinitionExists $exception) {
            return $this->refusal(Response::HTTP_CONFLICT, 'api.problem.metric_policy_definition_exists', 'policy_definition_exists', ['version' => $exception->existingVersion]);
        } catch (MetricPolicyVersionLimit) {
            return $this->refusal(Response::HTTP_CONFLICT, 'api.problem.metric_policy_version_limit', 'policy_version_limit');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.metric_policy_forbidden');
        }

        return new JsonResponse(MetricPolicyRepresentation::policy($policy), Response::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/reports/metric-policies/active', name: 'api_v1_reports_metric_policies_activate', methods: ['PUT'])]
    public function activate(Request $request, ActivateMetricPolicy $activate): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Small, 'api.problem.invalid_metric_policy');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['version', 'expectedActiveVersion'], ['reason']);
            $result = $activate(new ActivateMetricPolicyInput(
                $payload->integer('version'),
                $payload->integer('expectedActiveVersion'),
                $payload->nullableString('reason'),
            ));
        } catch (InvalidMetricPolicyInput|MalformedPayload) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_metric_policy');
        } catch (UnknownMetricPolicyVersion) {
            return $this->refusal(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.unknown_policy_version', 'unknown_policy_version');
        } catch (StaleActiveMetricPolicy) {
            return $this->refusal(Response::HTTP_CONFLICT, 'api.problem.stale_active_policy', 'stale_active_policy');
        } catch (MetricPolicyActivationLimit) {
            return $this->refusal(Response::HTTP_CONFLICT, 'api.problem.metric_policy_activation_limit', 'activation_limit');
        } catch (MetricPolicyAlreadyActive) {
            return $this->refusal(Response::HTTP_CONFLICT, 'api.problem.policy_already_active', 'policy_already_active');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.metric_policy_forbidden');
        }

        return new JsonResponse([
            'active' => MetricPolicyRepresentation::policy($result->active ?? throw new \LogicException('An activation result carries its policy.')),
            'activeSince' => $result->activeSince,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /**
     * A refusal the client tells apart by its code, carried as type and member.
     *
     * @param array<string, mixed> $extensions
     */
    private function refusal(int $status, string $key, string $code, array $extensions = []): JsonResponse
    {
        return $this->envelope->problem($status, $key, '/problems/'.str_replace('_', '-', $code), ['code' => $code, ...$extensions]);
    }
}
