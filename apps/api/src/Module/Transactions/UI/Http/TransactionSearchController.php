<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\CountTransactions;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ListTransactions;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Application\TransactionCursorStale;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Listing transactions: its own bounded, exact-field query contract, kept
 * apart from the CRUD surface because the shape of "search" (repeatable
 * filters, a cursor, a text query) has nothing in common with the shape of a
 * single resource's fields.
 */
final readonly class TransactionSearchController
{
    private const array LIST_QUERY_FIELDS = [
        'from', 'to', 'accountId', 'state', 'nature', 'categoryId', 'includeDescendants', 'axis',
        'minAmount', 'maxAmount', 'assetCode', 'source', 'categorization', 'q', 'includeVoided',
        'pageSize', 'cursor',
    ];

    public function __construct(private TransactionHttpEnvelope $envelope, private TransactionFilterShape $shape)
    {
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_list', methods: ['GET'])]
    public function list(Request $request, ListTransactions $listTransactions): Response
    {
        $listQuery = $this->parse($request, self::LIST_QUERY_FIELDS);
        if ($listQuery instanceof Response) {
            return $listQuery;
        }

        try {
            $page = $listTransactions($listQuery);
        } catch (InvalidTransactionInput) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        } catch (TransactionCursorStale) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_cursor_stale', '/problems/transactions.cursor_stale');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::page($page));
    }

    /**
     * The exact size of the filtered listing, so a filter-wide bulk action can
     * show and confirm the number it is about to touch. It takes the listing's
     * filters, without paging.
     */
    #[Route('/api/v1/transactions/count', name: 'api_v1_transactions_count', methods: ['GET'], priority: 10)]
    public function count(Request $request, CountTransactions $countTransactions): Response
    {
        $query = $this->parse($request, array_values(array_diff(self::LIST_QUERY_FIELDS, ['pageSize', 'cursor'])));
        if ($query instanceof Response) {
            return $query;
        }

        try {
            $count = $countTransactions($query);
        } catch (InvalidTransactionInput) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(['count' => $count]);
    }

    /** @param list<string> $allowedFields */
    private function parse(Request $request, array $allowedFields): ListTransactionsQuery|Response
    {
        if ([] !== array_diff(array_keys($request->query->all()), $allowedFields)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        }

        $from = $request->query->has('from') ? $request->query->getString('from') : null;
        $to = $request->query->has('to') ? $request->query->getString('to') : null;
        $includeVoided = $request->query->has('includeVoided') ? $request->query->getString('includeVoided') : null;
        $includeDescendants = $request->query->has('includeDescendants') ? $request->query->getString('includeDescendants') : null;
        $pageSize = $request->query->has('pageSize') ? $request->query->getString('pageSize') : null;
        $cursor = $request->query->has('cursor') ? $request->query->getString('cursor') : null;
        $categorization = $request->query->has('categorization') ? $request->query->getString('categorization') : null;
        $q = $request->query->has('q') ? $request->query->getString('q') : null;
        $minAmount = $request->query->has('minAmount') ? $request->query->getString('minAmount') : null;
        $maxAmount = $request->query->has('maxAmount') ? $request->query->getString('maxAmount') : null;
        $assetCode = $request->query->has('assetCode') ? $request->query->getString('assetCode') : null;

        $accountIds = self::repeatedQueryValues($request, 'accountId');
        $categoryIds = self::repeatedQueryValues($request, 'categoryId');
        $states = self::repeatedQueryValues($request, 'state');
        $natures = self::repeatedQueryValues($request, 'nature');
        $axes = self::repeatedQueryValues($request, 'axis');
        $sources = self::repeatedQueryValues($request, 'source');

        return $this->shape->query(
            $from, $to, $accountIds, $categoryIds, $states, $natures, $axes, $sources,
            $includeVoided, $includeDescendants, $categorization, $q, $minAmount, $maxAmount, $assetCode,
            $pageSize, $cursor,
        ) ?? $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
    }

    /**
     * Repeatable filters are declared in the OpenAPI contract as
     * `type: array` with `style: form, explode: true`, and the generated
     * client sends them as plain repeated keys (`?accountId=A&accountId=B`),
     * never bracketed. PHP's superglobal query parsing collapses repeated
     * non-bracket keys to the last value, and `$request->query` is built
     * from that superglobal — so reading it here would silently drop every
     * value but the last. Parsing the raw query string ourselves preserves
     * every repeated key, whether the client used brackets or not.
     *
     * @return list<string>
     */
    private static function repeatedQueryValues(Request $request, string $key): array
    {
        return self::rawQueryMultimap($request)[$key] ?? [];
    }

    /**
     * Groups every value of the raw, unparsed query string by key, treating
     * `key=`, `key[]=` and `key[0]=` as the same key. This is deliberately
     * independent from `$request->query`, which is derived from PHP's
     * superglobals and has already lost repeated non-bracket keys by the
     * time it reaches userland.
     *
     * @return array<string, list<string>>
     */
    private static function rawQueryMultimap(Request $request): array
    {
        $queryString = $request->server->get('QUERY_STRING');
        if (!is_string($queryString) || '' === $queryString) {
            return [];
        }

        $multimap = [];
        foreach (explode('&', $queryString) as $pair) {
            if ('' === $pair) {
                continue;
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            $key = preg_replace('/\[[^\]]*]$/', '', $key) ?? $key;
            $value = rawurldecode(str_replace('+', ' ', $rawValue));
            $multimap[$key][] = $value;
        }

        return $multimap;
    }
}
