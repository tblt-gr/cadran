<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Transactions\Domain\TransactionDirection;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * The part of a transaction filter that reads the transaction row alone: SQL over the alias
 * `t`, each with its bound value. The listing, its count and the facet counts all build their
 * statement from it, so no filter is read differently by one of them.
 *
 * The filters that look into the splits (the to-categorise queue, the categories and the axes)
 * are not here: they name the splits table, so the statements keep them beside their own
 * workspace predicate, where the static workspace-scope guard can read both. Only the values
 * of `:category_ids` and `:axes` are bound here, once, for those statements.
 */
final readonly class TransactionFilterPredicates
{
    /**
     * @param array<string, mixed>                            $parameters
     * @param array<string, ArrayParameterType|ParameterType> $types
     */
    private function __construct(
        public string $sql,
        public array $parameters,
        public array $types,
    ) {
    }

    public static function of(TransactionFilters $filters): self
    {
        $sql = '';
        $parameters = [];
        $types = [];

        // The default (no explicit state filter) state scope is a business
        // decision the caller has already resolved into a concrete, non-empty
        // list before reaching this repository: PENDING and BOOKED, plus
        // VOIDED only when includeVoided is set. An empty list here means no
        // restriction at all, so a caller can name every state explicitly.
        if ([] !== $filters->states) {
            $sql .= ' AND t.state IN (:states)';
            $parameters['states'] = array_map(static fn (TransactionState $state): string => $state->value, $filters->states);
            $types['states'] = ArrayParameterType::STRING;
        }
        if (null !== $filters->from) {
            $sql .= ' AND t.booked_on >= :from_date';
            $parameters['from_date'] = $filters->from->format('Y-m-d');
        }
        if (null !== $filters->to) {
            $sql .= ' AND t.booked_on <= :to_date';
            $parameters['to_date'] = $filters->to->format('Y-m-d');
        }
        if ([] !== $filters->accountIds) {
            $sql .= ' AND t.account_id IN (:account_ids)';
            $parameters['account_ids'] = $filters->accountIds;
            $types['account_ids'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->natures) {
            $sql .= ' AND t.nature IN (:natures)';
            $parameters['natures'] = array_map(static fn (TransactionNature $nature): string => $nature->value, $filters->natures);
            $types['natures'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->sources) {
            $sql .= ' AND t.source IN (:sources)';
            $parameters['sources'] = array_map(static fn (TransactionSource $source): string => $source->value, $filters->sources);
            $types['sources'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->categoryIds) {
            $parameters['category_ids'] = $filters->categoryIds;
            $types['category_ids'] = ArrayParameterType::STRING;
        }
        if ([] !== $filters->axes) {
            $parameters['axes'] = TransactionRow::postgresArray(array_map(static fn (AnalyticAxis $axis): string => $axis->value, $filters->axes));
        }
        if (null !== $filters->minAmount || null !== $filters->maxAmount) {
            $sql .= ' AND t.asset_code = :amount_asset_code';
            $parameters['amount_asset_code'] = $filters->assetCode?->toString();
        }
        if (null !== $filters->minAmount) {
            $sql .= ' AND abs(t.amount_value) >= :min_amount';
            $parameters['min_amount'] = $filters->minAmount->toString();
        }
        if (null !== $filters->maxAmount) {
            $sql .= ' AND abs(t.amount_value) <= :max_amount';
            $parameters['max_amount'] = $filters->maxAmount->toString();
        }
        if (null !== $filters->direction) {
            $sql .= TransactionDirection::INFLOW === $filters->direction ? ' AND t.amount_value > 0' : ' AND t.amount_value < 0';
        }
        if (null !== $filters->q) {
            // Simultaneous strtr: the backslash the escape introduces is never
            // re-escaped by the % or _ replacement that follows it.
            // normalized_label is a generated column equal to
            // lower(btrim(raw_label)): any row lower(raw_label) would match is
            // already matched by normalized_label, so there is no separate
            // raw_label branch (and no index needed for one). The user's own
            // display label is matched too, so a movement is found by either.
            $escaped = strtr(mb_strtolower($filters->q, 'UTF-8'), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
            $sql .= ' AND (t.normalized_label LIKE :pattern ESCAPE \'\\\' '
                .'OR lower(t.user_label) LIKE :pattern ESCAPE \'\\\' '
                .'OR lower(t.counterparty) LIKE :pattern ESCAPE \'\\\''
                .(null === $filters->qAmount ? '' : ' OR abs(t.amount_value) = :q_amount').')';
            $parameters['pattern'] = '%'.$escaped.'%';
            if (null !== $filters->qAmount) {
                $parameters['q_amount'] = $filters->qAmount->toString();
            }
        }

        return new self($sql, $parameters, $types);
    }
}
