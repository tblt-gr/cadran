<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountRuleOverrideConflict;
use App\Module\Accounts\Application\AccountRuleOverrideInput;
use App\Module\Accounts\Application\AccountRuleOverrideNotFound;
use App\Module\Accounts\Application\DeclaredRuleInput;
use App\Module\Accounts\Application\DeclaredRuleParser;
use App\Module\Accounts\Application\InvalidAccountRuleOverrideInput;
use App\Module\Accounts\Application\InvalidDeclaredRuleInput;
use App\Module\Accounts\Application\ListAccountRuleOverrides;
use App\Module\Accounts\Application\RateBracketInput;
use App\Module\Accounts\Application\RecordAccountRuleOverride;
use App\Module\Accounts\Application\ResolveAuthorNames;
use App\Module\Accounts\Application\WithdrawAccountRuleOverride;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adapts HTTP to the account rule-override use cases.
 *
 * They live apart from {@see AccountController} because an override is not a
 * field of an account: it has its own identity, its own author and its own
 * lifecycle, and a client must not be able to record or take one back by
 * replaying an account form.
 *
 * There is no endpoint that edits a recorded override, and none that deletes
 * one. A claim is recorded, and later withdrawn; both stay readable, which is
 * what keeps a past statement explainable.
 */
final readonly class AccountRuleOverrideController
{
    public const string TYPE_OVERRIDE_CONFLICT = '/problems/account-rule-override-conflict';
    public const string TYPE_ARCHIVED = '/problems/account-archived';

    private const int MAX_BODY_BYTES = 16_384;
    private const array OVERRIDE_FIELDS = [
        'kind', 'amount', 'amountAssetCode', 'text', 'rateApplication', 'brackets',
        'validFrom', 'validTo', 'reason',
    ];
    private const array BRACKET_FIELDS = ['lowerBound', 'upperBound', 'percentage'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * Every override ever recorded on the account, withdrawn ones included.
     * The history is the answer here; what applies on a given day is the
     * account's rules resource instead.
     */
    #[Route('/api/v1/accounts/{id}/rule-overrides', name: 'api_v1_account_rule_overrides_list', methods: ['GET'])]
    public function list(string $id, ListAccountRuleOverrides $listOverrides, ResolveAuthorNames $authors): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        try {
            $overrides = $listOverrides($id);
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        $ids = [];
        foreach ($overrides->overrides as $override) {
            $ids[] = $override->authorId;
        }

        return self::json(AccountRuleOverrideRepresentation::list($overrides, $authors->forIds($ids)));
    }

    #[Route('/api/v1/accounts/{id}/rule-overrides', name: 'api_v1_account_rule_overrides_record', methods: ['POST'])]
    public function record(string $id, Request $request, RecordAccountRuleOverride $recordOverride): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $override = $recordOverride($id, self::override(AccountPayload::of($body, self::OVERRIDE_FIELDS)));
        } catch (AccountArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountRuleOverrideInput|InvalidDeclaredRuleInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_rule_override');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (AccountRuleOverrideConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_rule_override_conflict', self::TYPE_OVERRIDE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountRuleOverrideRepresentation::one($override), Response::HTTP_CREATED);
    }

    /**
     * Withdrawing is its own resource rather than a DELETE: the row is kept,
     * and what changes is that it stops applying on every date. A reader of
     * the trail must still be able to see the claim that was taken back.
     */
    #[Route(
        '/api/v1/accounts/{id}/rule-overrides/{overrideId}/withdraw',
        name: 'api_v1_account_rule_overrides_withdraw',
        methods: ['POST'],
    )]
    public function withdraw(
        string $id,
        string $overrideId,
        Request $request,
        WithdrawAccountRuleOverride $withdrawOverride,
    ): Response {
        if (!self::identifier($id) || !self::identifier($overrideId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_rule_override_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            // The body carries no field. It is still read and narrowed, so a
            // replayed form cannot smuggle one in and a client cannot reach
            // this route with a payload it believes was applied.
            AccountPayload::of($body, []);
            $override = $withdrawOverride($id, $overrideId);
        } catch (AccountArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_rule_override');
        } catch (AccountNotFound|AccountRuleOverrideNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_rule_override_not_found');
        } catch (AccountRuleOverrideConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_rule_override_conflict', self::TYPE_OVERRIDE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountRuleOverrideRepresentation::one($override));
    }

    private static function override(AccountPayload $payload): AccountRuleOverrideInput
    {
        return new AccountRuleOverrideInput(
            rule: new DeclaredRuleInput(
                kind: $payload->string('kind'),
                amount: $payload->raw('amount'),
                amountAssetCode: $payload->raw('amountAssetCode'),
                text: $payload->nullableString('text'),
                rateApplication: $payload->nullableString('rateApplication'),
                brackets: array_map(
                    static fn (AccountPayload $bracket): RateBracketInput => new RateBracketInput(
                        lowerBound: $bracket->string('lowerBound'),
                        upperBound: $bracket->nullableString('upperBound'),
                        percentage: $bracket->string('percentage'),
                    ),
                    $payload->objects('brackets', self::BRACKET_FIELDS, DeclaredRuleParser::MAX_BRACKETS),
                ),
                validFrom: $payload->string('validFrom'),
                validTo: $payload->nullableString('validTo'),
            ),
            reason: $payload->string('reason'),
        );
    }

    /**
     * Envelope checks only: the media type, the size ceiling and the JSON
     * syntax each answer their own status, so they stay beside the mapping
     * rather than travelling as exceptions.
     *
     * @return array<mixed>|Response
     */
    private function readBody(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.account_payload_too_large');
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
    }

    private static function identifier(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $translationKey, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
        );
    }
}
