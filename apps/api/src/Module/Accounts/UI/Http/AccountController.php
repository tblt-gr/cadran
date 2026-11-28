<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ListAccounts;
use App\Module\Accounts\Application\PresentAccountRules;
use App\Module\Accounts\Application\ReadAccount;
use App\Module\Accounts\Application\ReadAccountRules;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Foundation\UI\Http\QueryParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adapts HTTP to the account use cases. Field-level validation lives in
 * {@see JsonPayload}, the wire shape in {@see AccountRepresentation} and the
 * status mapping in {@see AccountProblems}; what remains here is the routing.
 * Writes live in {@see AccountWriteController}.
 */
final readonly class AccountController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/accounts', name: 'api_v1_accounts_list', methods: ['GET'])]
    public function list(Request $request, ListAccounts $listAccounts): Response
    {
        $page = $request->query->getString('page');
        $pageSize = $request->query->getString('pageSize');
        $includeArchived = $request->query->getString('includeArchived');
        $includeClosed = $request->query->getString('includeClosed');
        $kind = $request->query->getString('kind');
        $asOf = $request->query->getString('asOf');
        if (!QueryParameters::isUnsignedIntegerOrEmpty($page) || !QueryParameters::isUnsignedIntegerOrEmpty($pageSize)
            || !QueryParameters::isFlagOrEmpty($includeArchived) || !QueryParameters::isFlagOrEmpty($includeClosed)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_query');
        }

        try {
            $accounts = $listAccounts(
                'true' === $includeArchived,
                'true' === $includeClosed,
                QueryParameters::integerOrNull($page),
                QueryParameters::integerOrNull($pageSize),
                '' === $kind ? null : $kind,
                '' === $asOf ? null : $asOf,
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::list());
        }

        return $this->envelope->json(AccountRepresentation::page($accounts));
    }

    /**
     * One account, closed and archived ones included: a detail page has to stay
     * linkable once the account leaves the working set, or a past statement
     * stops being re-readable.
     *
     * A malformed identifier, an unknown one and one belonging to another
     * workspace all answer the same not-found problem. Telling them apart would
     * confirm the existence of a stranger's account to whoever enumerates
     * identifiers.
     */
    #[Route('/api/v1/accounts/{id}', name: 'api_v1_accounts_show', methods: ['GET'])]
    public function show(string $id, ReadAccount $readAccount): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        try {
            $account = $readAccount($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::show());
        }

        return $this->envelope->json(AccountRepresentation::one($account));
    }

    /**
     * The rules in force for the account, resolved on a business date. They
     * are a resource of their own because they are not part of the account:
     * nothing here is stored on it, and the same account answers differently
     * for two different dates.
     */
    #[Route('/api/v1/accounts/{id}/rules', name: 'api_v1_accounts_rules', methods: ['GET'])]
    public function rules(
        string $id,
        Request $request,
        ReadAccountRules $readAccountRules,
        PresentAccountRules $presentAccountRules,
    ): Response {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $asOf = $request->query->getString('asOf');

        try {
            $rules = $readAccountRules($id, '' === $asOf ? null : $asOf);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::rules());
        }

        return $this->envelope->json(AccountRulesRepresentation::of($rules, $presentAccountRules($rules)));
    }
}
