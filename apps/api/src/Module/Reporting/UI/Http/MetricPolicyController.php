<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/** Lists, creates and activates the workspace metric policy versions. No version is ever edited or deleted. */
final readonly class MetricPolicyController
{
    private const int MAX_BODY_BYTES = 4096;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/reports/metric-policies', name: 'api_v1_reports_metric_policies_list', methods: ['GET'])]
    public function list(ReadMetricPolicies $read): Response
    {
        try {
            $catalog = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return new JsonResponse(MetricPolicyRepresentation::catalog($catalog), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/reports/metric-policies', name: 'api_v1_reports_metric_policies_create', methods: ['POST'])]
    public function create(Request $request, CreateMetricPolicy $create): Response
    {
        $body = $this->body($request, ['label', 'cashExcludedAccountKinds']);
        if ($body instanceof Response) {
            return $body;
        }
        $kinds = $body['cashExcludedAccountKinds'];
        if (!is_string($body['label']) || !is_array($kinds) || !array_is_list($kinds) || [] !== array_filter($kinds, static fn (mixed $kind): bool => !is_string($kind))) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_metric_policy');
        }

        try {
            /** @var list<string> $kinds */
            $policy = $create(new CreateMetricPolicyInput($body['label'], $kinds));
        } catch (InvalidMetricPolicyInput) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_metric_policy');
        } catch (MetricPolicyDefinitionExists $exception) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.metric_policy_definition_exists', 'policy_definition_exists', ['version' => $exception->existingVersion]);
        } catch (MetricPolicyVersionLimit) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.metric_policy_version_limit', 'policy_version_limit');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.metric_policy_forbidden');
        }

        return new JsonResponse(MetricPolicyRepresentation::policy($policy), Response::HTTP_CREATED, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/reports/metric-policies/active', name: 'api_v1_reports_metric_policies_activate', methods: ['PUT'])]
    public function activate(Request $request, ActivateMetricPolicy $activate): Response
    {
        $body = $this->body($request, ['version', 'expectedActiveVersion'], ['reason']);
        if ($body instanceof Response) {
            return $body;
        }
        $reason = $body['reason'] ?? null;
        if (!is_int($body['version']) || !is_int($body['expectedActiveVersion']) || (null !== $reason && !is_string($reason))) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_metric_policy');
        }

        try {
            $result = $activate(new ActivateMetricPolicyInput($body['version'], $body['expectedActiveVersion'], $reason));
        } catch (InvalidMetricPolicyInput) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_metric_policy');
        } catch (UnknownMetricPolicyVersion) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.unknown_policy_version', 'unknown_policy_version');
        } catch (StaleActiveMetricPolicy) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.stale_active_policy', 'stale_active_policy');
        } catch (MetricPolicyActivationLimit) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.metric_policy_activation_limit', 'activation_limit');
        } catch (MetricPolicyAlreadyActive) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.policy_already_active', 'policy_already_active');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.metric_policy_forbidden');
        }

        return new JsonResponse([
            'active' => MetricPolicyRepresentation::policy($result->active ?? throw new \LogicException('An activation result carries its policy.')),
            'activeSince' => $result->activeSince,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @return array<mixed>|Response
     */
    private function body(Request $request, array $required, array $optional = []): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.invalid_metric_policy');
        }
        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
        $keys = array_keys($body);
        if ([] !== array_diff($required, $keys) || [] !== array_diff($keys, [...$required, ...$optional])) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_metric_policy');
        }

        return $body;
    }

    /** @param array<string, mixed> $extensions */
    private function problem(int $status, string $key, ?string $code = null, array $extensions = []): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail'),
            null === $code ? ApiProblem::TYPE_BLANK : '/problems/'.str_replace('_', '-', $code),
            [],
            null === $code ? $extensions : ['code' => $code, ...$extensions],
        );
    }
}
