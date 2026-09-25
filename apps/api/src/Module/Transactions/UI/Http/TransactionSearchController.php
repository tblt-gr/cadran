<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ListTransactions;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Application\TransactionCursorStale;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
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
    private const int MAX_MULTI_VALUE_FILTER = 20;
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    public function __construct(private TransactionHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_list', methods: ['GET'])]
    public function list(Request $request, ListTransactions $listTransactions): Response
    {
        if ([] !== array_diff(array_keys($request->query->all()), self::LIST_QUERY_FIELDS)) {
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

        if (count($accountIds) > self::MAX_MULTI_VALUE_FILTER || count($categoryIds) > self::MAX_MULTI_VALUE_FILTER
            || count($states) > self::MAX_MULTI_VALUE_FILTER || count($natures) > self::MAX_MULTI_VALUE_FILTER
            || count($axes) > self::MAX_MULTI_VALUE_FILTER || count($sources) > self::MAX_MULTI_VALUE_FILTER
            || !self::allMatch($accountIds, $this->envelope->isIdentifier(...))
            || !self::allMatch($categoryIds, $this->envelope->isIdentifier(...))
            || !self::allMatch($states, static fn (string $value): bool => null !== TransactionState::tryFrom($value))
            || !self::allMatch($natures, static fn (string $value): bool => null !== TransactionNature::tryFrom($value))
            || !self::allMatch($axes, static fn (string $value): bool => null !== AnalyticAxis::tryFrom($value))
            || !self::allMatch($sources, static fn (string $value): bool => null !== TransactionSource::tryFrom($value))
            || (null !== $from && 1 !== preg_match(self::DATE_PATTERN, $from))
            || (null !== $to && 1 !== preg_match(self::DATE_PATTERN, $to))
            || (null !== $includeVoided && !in_array($includeVoided, ['true', 'false'], true))
            || (null !== $includeDescendants && !in_array($includeDescendants, ['true', 'false'], true))
            || (null !== $pageSize && 1 !== preg_match('/^[0-9]{1,3}$/D', $pageSize))
            || (null !== $cursor && ('' === $cursor || strlen($cursor) > 200))
            || (null !== $categorization && !in_array($categorization, ['ANY', 'NONE'], true))
            || (null !== $q && mb_strlen($q, 'UTF-8') > ListTransactions::MAX_TEXT_QUERY_LENGTH)
            || (null !== $assetCode && '' === $assetCode)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        }

        try {
            $page = $listTransactions(new ListTransactionsQuery(
                from: $from,
                to: $to,
                accountIds: $accountIds,
                states: $states,
                natures: $natures,
                categoryIds: $categoryIds,
                includeDescendants: 'true' === $includeDescendants,
                axes: $axes,
                minAmount: $minAmount,
                maxAmount: $maxAmount,
                assetCode: $assetCode,
                sources: $sources,
                categorizationNone: 'NONE' === $categorization,
                q: $q,
                includeVoided: 'true' === $includeVoided,
                pageSize: null === $pageSize ? null : (int) $pageSize,
                cursor: $cursor,
            ));
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

    /** @param list<string> $values */
    private static function allMatch(array $values, \Closure $predicate): bool
    {
        foreach ($values as $value) {
            if (!$predicate($value)) {
                return false;
            }
        }

        return true;
    }
}
