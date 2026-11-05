<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionDirection;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Turns the raw filters of a listing, a count or a bulk filter scope into the
 * one validated {@see TransactionFilters}, so the three can never disagree on
 * what a filter matches.
 */
final readonly class ResolveTransactionFilters
{
    private const int MAX_CATEGORIES_OF_TYPE = 10000;

    public function __construct(private CategoryRepository $categories)
    {
    }

    /**
     * @return TransactionFilters|null null when the state scope is empty, i.e. no transaction can match
     *
     * @throws InvalidTransactionInput
     */
    public function __invoke(WorkspaceScope $workspace, ListTransactionsQuery $query): ?TransactionFilters
    {
        if (null !== $query->q && mb_strlen($query->q, 'UTF-8') > ListTransactions::MAX_TEXT_QUERY_LENGTH) {
            throw new InvalidTransactionInput('The free-text query is too long.');
        }

        $from = self::parseDate($query->from);
        $to = self::parseDate($query->to);
        if (null !== $from && null !== $to && $from > $to) {
            throw new InvalidTransactionInput('The period lower bound must not be after its upper bound.');
        }

        [$minAmount, $maxAmount, $assetCode] = $this->parseAmountBounds($query);
        $states = self::resolveStates($query);
        $categoryIds = $this->resolveCategoryIds($workspace, $query);

        // The repository reads an empty state list as "every state" and an empty category list as
        // "no category filter", so a request whose states or categories were all excluded is
        // answered by the caller.
        if ([] === $states || (null !== $categoryIds && [] === $categoryIds)) {
            return null;
        }

        return new TransactionFilters(
            from: $from,
            to: $to,
            accountIds: $query->accountIds,
            states: $states,
            natures: array_map(static fn (string $nature): TransactionNature => TransactionNature::from($nature), $query->natures),
            categoryIds: $categoryIds ?? [],
            axes: array_map(static fn (string $axis): AnalyticAxis => AnalyticAxis::from($axis), $query->axes),
            minAmount: $minAmount,
            maxAmount: $maxAmount,
            assetCode: $assetCode,
            sources: array_map(static fn (string $source): TransactionSource => TransactionSource::from($source), $query->sources),
            categorizationNone: $query->categorizationNone,
            q: $query->q,
            direction: null === $query->direction ? null : (TransactionDirection::tryFrom($query->direction)
                ?? throw new InvalidTransactionInput('The direction must be INFLOW or OUTFLOW.')),
            qAmount: self::parseAmountQuery($query->q),
        );
    }

    private static function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidTransactionInput('A transaction search date must be an ISO 8601 calendar day.');
        }

        return $date;
    }

    /**
     * Reads a free-text query as an unsigned amount (`10`, `-10,50 €`), or null
     * when it is anything else, in which case it stays a plain text search.
     */
    private static function parseAmountQuery(?string $q): ?DecimalValue
    {
        if (null === $q) {
            return null;
        }
        $literal = str_replace([' ', "\u{00A0}"], '', trim($q));
        if (str_ends_with($literal, '€')) {
            $literal = substr($literal, 0, -strlen('€'));
        }
        if (str_starts_with($literal, '+') || str_starts_with($literal, '-')) {
            $literal = substr($literal, 1);
        }
        if (substr_count($literal, ',') <= 1) {
            $literal = str_replace(',', '.', $literal);
        }
        if (1 !== preg_match('/^\d{1,26}(\.\d{1,24})?$/D', $literal)) {
            return null;
        }
        // The canonical form has no leading zero beyond a lone one.
        $literal = preg_replace('/^0+(?=\d)/', '', $literal) ?? $literal;

        return DecimalValue::fromString($literal);
    }

    /** @return array{0: ?DecimalValue, 1: ?DecimalValue, 2: ?AssetCode} */
    private function parseAmountBounds(ListTransactionsQuery $query): array
    {
        if (null === $query->minAmount && null === $query->maxAmount) {
            return [null, null, null];
        }
        if (null === $query->assetCode) {
            throw new InvalidTransactionInput('An amount bound requires an asset code.');
        }
        try {
            $assetCode = AssetCode::fromString($query->assetCode);
            $minAmount = null === $query->minAmount ? null : DecimalValue::fromString($query->minAmount);
            $maxAmount = null === $query->maxAmount ? null : DecimalValue::fromString($query->maxAmount);
        } catch (MalformedDecimal|PrecisionExceeded|\InvalidArgumentException $exception) {
            throw new InvalidTransactionInput('An amount bound must be a canonical decimal with a valid asset code.', previous: $exception);
        }
        // Bounds compare the absolute value of a movement, so a sign has no meaning here.
        if ($minAmount?->isNegative() || $maxAmount?->isNegative()) {
            throw new InvalidTransactionInput('An amount bound must not be negative.');
        }
        if (null !== $minAmount && null !== $maxAmount && $minAmount->compareTo($maxAmount) > 0) {
            throw new InvalidTransactionInput('The amount lower bound must not be above its upper bound.');
        }

        return [$minAmount, $maxAmount, $assetCode];
    }

    /**
     * An empty list means no state can match, never "no restriction".
     *
     * @return list<TransactionState>
     */
    private static function resolveStates(ListTransactionsQuery $query): array
    {
        if ([] !== $query->states) {
            $states = array_map(static fn (string $state): TransactionState => TransactionState::from($state), $query->states);
        } else {
            // Without an explicit state filter, REJECTED is never silently
            // included by a flag named for voiding, and VOIDED only appears when
            // includeVoided asks for it.
            $states = [TransactionState::PENDING, TransactionState::BOOKED];
            if ($query->includeVoided) {
                $states[] = TransactionState::VOIDED;
            }
        }

        // A voided movement never needs categorising, so the "to categorise"
        // queue excludes it whatever includeVoided or the state filter ask.
        if ($query->categorizationNone) {
            $states = array_values(array_filter($states, static fn (TransactionState $state): bool => TransactionState::VOIDED !== $state));
        }

        return $states;
    }

    /**
     * @return list<string>|null null when no category filter applies; an empty list when one applies
     *                           but leaves no category, i.e. nothing can match
     */
    private function resolveCategoryIds(WorkspaceScope $workspace, ListTransactionsQuery $query): ?array
    {
        $type = null === $query->categoryType ? null : (CategoryType::tryFrom($query->categoryType)
            ?? throw new InvalidTransactionInput('The category type must be INCOME or EXPENSE.'));

        $explicit = $this->expandCategoryIds($workspace, $query);
        if (null === $type) {
            return [] === $explicit ? null : $explicit;
        }

        $ofType = $this->categoryIdsOfType($workspace, $type);

        return [] === $query->categoryIds ? $ofType : array_values(array_intersect($explicit, $ofType));
    }

    /** @return list<string> */
    private function expandCategoryIds(WorkspaceScope $workspace, ListTransactionsQuery $query): array
    {
        $categoryIds = $query->categoryIds;
        if ($query->includeDescendants) {
            foreach ($query->categoryIds as $categoryId) {
                foreach ($this->categories->descendants($workspace, $categoryId) as $descendant) {
                    $categoryIds[] = $descendant->id;
                }
            }
        }

        return array_values(array_unique($categoryIds));
    }

    /** @return list<string> every category of the workspace with that type, archived ones included */
    private function categoryIdsOfType(WorkspaceScope $workspace, CategoryType $type): array
    {
        // Safety bound only: a workspace holds far fewer categories, and truncating silently would
        // widen nothing (the filter would just match fewer transactions), so no error is raised.
        $categories = $this->categories->list($workspace, true, self::MAX_CATEGORIES_OF_TYPE, 0, $type);

        return array_map(static fn ($category): string => $category->id, $categories);
    }
}
