<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Foundation\Domain\WorkspaceScope;
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

        // The repository reads an empty state list as "every state", so a
        // request whose states were all excluded is answered by the caller.
        if ([] === $states) {
            return null;
        }

        return new TransactionFilters(
            from: $from,
            to: $to,
            accountIds: $query->accountIds,
            states: $states,
            natures: array_map(static fn (string $nature): TransactionNature => TransactionNature::from($nature), $query->natures),
            categoryIds: $categoryIds,
            axes: array_map(static fn (string $axis): AnalyticAxis => AnalyticAxis::from($axis), $query->axes),
            minAmount: $minAmount,
            maxAmount: $maxAmount,
            assetCode: $assetCode,
            sources: array_map(static fn (string $source): TransactionSource => TransactionSource::from($source), $query->sources),
            categorizationNone: $query->categorizationNone,
            q: $query->q,
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

    /** @return list<string> */
    private function resolveCategoryIds(WorkspaceScope $workspace, ListTransactionsQuery $query): array
    {
        if ([] === $query->categoryIds) {
            return [];
        }
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
}
