<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\Categorization\ApplyCategorizationRules;
use App\Module\Transactions\Application\Categorization\ArchiveCategorizationRule;
use App\Module\Transactions\Application\Categorization\CategorizationRangeTooLarge;
use App\Module\Transactions\Application\Categorization\CategorizationRuleNotFound;
use App\Module\Transactions\Application\Categorization\CreateCategorizationRule;
use App\Module\Transactions\Application\Categorization\InvalidCategorizationReference;
use App\Module\Transactions\Application\Categorization\InvalidCategorizationRuleInput;
use App\Module\Transactions\Application\Categorization\ListCategorizationRules;
use App\Module\Transactions\Application\Categorization\PreviewCategorizationRules;
use App\Module\Transactions\Application\Categorization\PreviewStale;
use App\Module\Transactions\Application\Categorization\StaleCategorizationRule;
use App\Module\Transactions\Application\Categorization\UpdateCategorizationRule;
use App\Module\Transactions\Domain\Categorization\UnsafeRulePattern;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CategorizationRuleController
{
    private const array CREATE_FIELDS = ['label', 'priority', 'accountScope', 'conditions', 'targetCategoryId', 'targetAxes', 'targetCounterparty', 'effectiveFrom', 'effectiveTo'];
    private const array UPDATE_FIELDS = ['label', 'priority', 'accountScope', 'conditions', 'targetCategoryId', 'targetAxes', 'targetCounterparty', 'effectiveFrom', 'effectiveTo', 'active', 'version'];

    public function __construct(private TransactionHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/categorization-rules', name: 'api_v1_categorization_rules_list', methods: ['GET'])]
    public function list(Request $request, ListCategorizationRules $list): Response
    {
        if ([] !== array_diff(array_keys($request->query->all()), ['includeArchived', 'page', 'perPage'])) {
            return $this->problem(400, '/problems/rules.invalid_query');
        }
        $archived = $request->query->getString('includeArchived');
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        if (!in_array($archived, ['', 'true', 'false'], true) || !self::digits($page) || !self::digits($perPage)) {
            return $this->problem(400, '/problems/rules.invalid_query');
        }
        try {
            return $this->envelope->json($list('true' === $archived, '' === $page ? 1 : (int) $page, '' === $perPage ? 50 : (int) $perPage));
        } catch (InvalidCategorizationRuleInput) {
            return $this->problem(400, '/problems/rules.invalid_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/rules.forbidden');
        }
    }

    #[Route('/api/v1/categorization-rules', name: 'api_v1_categorization_rules_create', methods: ['POST'])]
    public function create(Request $request, CreateCategorizationRule $create): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $view = $create(CategorizationRulePayload::of($body, self::CREATE_FIELDS)->input(false));
        } catch (UnsafeRulePattern $exception) {
            return $this->problem(422, '/problems/rules.unsafe_pattern', ['reason' => $exception->reason, 'field' => $exception->field]);
        } catch (InvalidCategorizationReference) {
            return $this->problem(422, '/problems/rules.invalid_reference');
        } catch (InvalidCategorizationRuleInput|\UnexpectedValueException) {
            return $this->problem(422, '/problems/rules.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/rules.forbidden');
        }

        return $this->envelope->json($view, 201);
    }

    #[Route('/api/v1/categorization-rules/{id}', name: 'api_v1_categorization_rules_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateCategorizationRule $update): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->problem(404, '/problems/rules.not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $view = $update($id, CategorizationRulePayload::of($body, self::UPDATE_FIELDS)->input(true));
        } catch (UnsafeRulePattern $exception) {
            return $this->problem(422, '/problems/rules.unsafe_pattern', ['reason' => $exception->reason, 'field' => $exception->field]);
        } catch (InvalidCategorizationReference) {
            return $this->problem(422, '/problems/rules.invalid_reference');
        } catch (CategorizationRuleNotFound) {
            return $this->problem(404, '/problems/rules.not_found');
        } catch (StaleCategorizationRule $exception) {
            return 'archived' === $exception->getMessage() ? $this->problem(409, '/problems/rules.archived') : $this->problem(409, '/problems/stale-version');
        } catch (InvalidCategorizationRuleInput|\UnexpectedValueException) {
            return $this->problem(422, '/problems/rules.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/rules.forbidden');
        }

        return $this->envelope->json($view);
    }

    #[Route('/api/v1/categorization-rules/{id}/archive', name: 'api_v1_categorization_rules_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveCategorizationRule $archive): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->problem(404, '/problems/rules.not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = CategorizationRulePayload::of($body, ['version']);

            return $this->envelope->json($archive($id, $payload->integer('version')));
        } catch (CategorizationRuleNotFound) {
            return $this->problem(404, '/problems/rules.not_found');
        } catch (StaleCategorizationRule) {
            return $this->problem(409, '/problems/stale-version');
        } catch (\UnexpectedValueException) {
            return $this->problem(422, '/problems/rules.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/rules.forbidden');
        }
    }

    #[Route('/api/v1/categorization-rules/preview', name: 'api_v1_categorization_rules_preview', methods: ['POST'], priority: 10)]
    public function preview(Request $request, PreviewCategorizationRules $preview): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = CategorizationRulePayload::of($body, ['ruleId', 'from', 'to']);
            $ruleId = $payload->nullableString('ruleId');
            if (null !== $ruleId && !$this->envelope->isIdentifier($ruleId)) {
                throw new \UnexpectedValueException();
            }

            return $this->envelope->json($preview($ruleId, self::day($payload->string('from')), self::day($payload->string('to'))));
        } catch (CategorizationRuleNotFound) {
            return $this->problem(404, '/problems/rules.not_found');
        } catch (CategorizationRangeTooLarge) {
            return $this->problem(422, '/problems/rules.range_too_large');
        } catch (InvalidCategorizationRuleInput|\UnexpectedValueException) {
            return $this->problem(422, '/problems/rules.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/rules.forbidden');
        }
    }

    #[Route('/api/v1/categorization-rules/apply', name: 'api_v1_categorization_rules_apply', methods: ['POST'], priority: 10)]
    public function apply(Request $request, ApplyCategorizationRules $apply): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = CategorizationRulePayload::of($body, ['previewToken']);
            $token = $payload->string('previewToken');
            if (1 !== preg_match('/^[0-9a-f]{64}$/D', $token)) {
                throw new \UnexpectedValueException();
            }

            return $this->envelope->json($apply($token));
        } catch (PreviewStale) {
            return $this->problem(409, '/problems/rules.preview_stale');
        } catch (\UnexpectedValueException) {
            return $this->problem(422, '/problems/rules.invalid');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(403, '/problems/rules.forbidden');
        }
    }

    /** @param array<string, mixed> $extensions */
    private function problem(int $status, string $type, array $extensions = []): Response
    {
        return $this->envelope->problemWithExtensions($status, 'api.problem.invalid_transaction', $extensions, $type);
    }

    private static function digits(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,3}$/D', $value);
    }

    private static function day(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException();
        }

        return $date;
    }
}
