<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountRuleOverrideInput;
use App\Module\Accounts\Application\DeclaredRuleInput;
use App\Module\Accounts\Application\DeclaredRuleParser;
use App\Module\Accounts\Application\ListAccountRuleOverrides;
use App\Module\Accounts\Application\RateBracketInput;
use App\Module\Accounts\Application\RecordAccountRuleOverride;
use App\Module\Accounts\Application\ResolveAuthorNames;
use App\Module\Accounts\Application\WithdrawAccountRuleOverride;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    private const array OVERRIDE_FIELDS = [
        'kind', 'amount', 'amountAssetCode', 'text', 'rateApplication', 'brackets',
        'validFrom', 'validTo', 'reason',
    ];
    private const array BRACKET_FIELDS = ['lowerBound', 'upperBound', 'percentage'];

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
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
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        try {
            $overrides = $listOverrides($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::show());
        }

        $ids = [];
        foreach ($overrides->overrides as $override) {
            $ids[] = $override->authorId;
        }

        return $this->envelope->json(AccountRuleOverrideRepresentation::list($overrides, $authors->forIds($ids)));
    }

    #[Route('/api/v1/accounts/{id}/rule-overrides', name: 'api_v1_account_rule_overrides_record', methods: ['POST'])]
    public function record(string $id, Request $request, RecordAccountRuleOverride $recordOverride): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $override = $recordOverride($id, self::override(AccountPayload::of($body, self::OVERRIDE_FIELDS)));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::ruleOverrides());
        }

        return $this->envelope->json(AccountRuleOverrideRepresentation::one($override), Response::HTTP_CREATED);
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
        if (!Identifier::isValid($id) || !Identifier::isValid($overrideId)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_rule_override_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            // The body carries no field. It is still read and narrowed, so a
            // replayed form cannot smuggle one in and a client cannot reach
            // this route with a payload it believes was applied.
            AccountPayload::of($body, []);
            $override = $withdrawOverride($id, $overrideId);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::withdrawRuleOverride());
        }

        return $this->envelope->json(AccountRuleOverrideRepresentation::one($override));
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
}
