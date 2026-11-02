import { readBudgetComparisons, type BudgetComparison } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { authApiOptions } from '@/features/auth/apiOptions';
import { budgetRequestError } from '@/features/budget/budgetError';
import { UpcomingNotice } from '@/features/settings/interface/UpcomingNotice';
import { useIncludeUpcoming } from '@/features/settings/interface/useIncludeUpcoming';
import { BudgetComparisonsPanelSkeleton } from './BudgetComparisonsPanelSkeleton';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './BudgetComparisonsPanel.module.css';

const statusTones = {
  WITHIN_TARGET: 'positive',
  ON_TARGET: 'info',
  OVER_TARGET: 'negative',
  NON_CALCULABLE: 'warning',
} as const;

function requestFailed(result: { response?: Response; data?: unknown }) {
  return !result.response?.ok || !result.data;
}

function Figure({
  value,
  assetCode,
  language,
}: {
  value: string | null;
  assetCode: string;
  language: string;
}) {
  if (value === null) return <span>—</span>;

  return <MoneyValue value={formatAmount(value, assetCode, language)} />;
}

function ComparisonCard({
  comparison,
  assetCode,
}: {
  comparison: BudgetComparison;
  assetCode: string;
}) {
  const { t, i18n } = useTranslation();

  return (
    <article className={`card ${styles.card}`}>
      <div className={styles.heading}>
        <h4>
          {t(`budget.scopeTypes.${comparison.scopeType}`)} · {comparison.scopeLabel}
        </h4>
        <StatusBadge tone={statusTones[comparison.status]}>
          {t(`budget.comparisons.statuses.${comparison.status}`)}
        </StatusBadge>
      </div>
      <dl className={styles.figures}>
        <div>
          <dt>{t('budget.comparisons.actual')}</dt>
          <dd>
            <Figure assetCode={assetCode} language={i18n.language} value={comparison.actual} />
            {comparison.upcomingAmount !== null &&
            !/^-?0(?:\.0+)?$/.test(comparison.upcomingAmount) ? (
              <small className={styles.upcoming}>
                {t('budget.comparisons.upcomingAmount', {
                  amount: formatAmount(comparison.upcomingAmount, assetCode, i18n.language),
                })}
              </small>
            ) : null}
          </dd>
        </div>
        <div>
          <dt>{t('budget.comparisons.target')}</dt>
          <dd>
            <Figure assetCode={assetCode} language={i18n.language} value={comparison.target} />
          </dd>
        </div>
        <div>
          <dt>{t('budget.comparisons.variance')}</dt>
          <dd>
            <span
              role="img"
              aria-label={[
                t('budget.comparisons.variance'),
                comparison.variance === null
                  ? t('budget.nonCalculable')
                  : `${comparison.variance.startsWith('-') || /^0(?:\.0+)?$/.test(comparison.variance) ? '' : '+'}${formatAmount(comparison.variance, assetCode, i18n.language)}`,
                t(`budget.comparisons.statuses.${comparison.status}`),
              ].join(' : ')}
            >
              <Figure assetCode={assetCode} language={i18n.language} value={comparison.variance} />
            </span>
          </dd>
        </div>
      </dl>
      {comparison.actualReason ? (
        <p className={styles.reason}>{t(`budget.reasons.${comparison.actualReason}`)}</p>
      ) : null}
      {comparison.targetReason ? (
        <p className={styles.reason}>{t(`budget.reasons.${comparison.targetReason}`)}</p>
      ) : null}
      {comparison.pendingCount > 0 ? (
        <p className={styles.pending}>
          {t('budget.comparisons.pending', { count: comparison.pendingCount })}
        </p>
      ) : null}
      {comparison.overlapping ? <p className={styles.warning}>{t('budget.overlap')}</p> : null}
      <details className={styles.policy}>
        <summary>{t('budget.comparisons.policy.summary')}</summary>
        <p>{t('budget.comparisons.policy.description')}</p>
      </details>
      <details className={styles.sources}>
        <summary>
          {t('budget.comparisons.sources', { count: comparison.includedTransactions.length })}
        </summary>
        <ul>
          {comparison.includedTransactions.map((transaction) => (
            <li className={styles.source} key={transaction.id}>
              <time dateTime={transaction.bookedOn}>
                {formatCalendarDay(transaction.bookedOn, i18n.language)}
              </time>
              <span>
                {transaction.rawLabel}
                {transaction.upcoming ? ` (${t('budget.comparisons.upcoming')})` : ''}
              </span>
              <MoneyValue
                value={formatAmount(transaction.amount, transaction.assetCode, i18n.language)}
              />
            </li>
          ))}
        </ul>
      </details>
    </article>
  );
}

/** Server-owned comparison figures: this component never derives financial values. */
export function BudgetComparisonsPanel({ planId }: { planId: string }) {
  const { t } = useTranslation();
  const upcoming = useIncludeUpcoming();
  const comparisons = useQuery({
    enabled: upcoming.ready,
    queryKey: ['budget-comparisons', planId, upcoming.includeUpcoming],
    queryFn: async ({ signal }) => {
      const result = await readBudgetComparisons({
        ...authApiOptions(),
        path: { id: planId },
        query: { includeUpcoming: upcoming.includeUpcoming },
        signal,
      });
      if (requestFailed(result)) throw budgetRequestError(result);
      return result.data!;
    },
    retry: false,
  });

  if (comparisons.isPending && comparisons.data === undefined)
    return <BudgetComparisonsPanelSkeleton status={t('budget.comparisons.loading')} />;
  if (comparisons.isError || comparisons.data === undefined) {
    return (
      <div className={styles.alert} role="alert">
        <p>{t('budget.comparisons.error')}</p>
        <button
          className="secondary-action"
          onClick={() => void comparisons.refetch()}
          type="button"
        >
          {t('foundation.retry')}
        </button>
      </div>
    );
  }
  if (comparisons.data.status === 'NO_TARGETS') {
    return (
      <p className={styles.state} role="status">
        {t('budget.comparisons.empty')}
        {comparisons.data.reason ? ` ${t(`budget.reasons.${comparisons.data.reason}`)}` : ''}
      </p>
    );
  }

  // The API counts upcoming rows per comparison; one row can sit in several scopes, so count it once.
  const upcomingSources = new Set(
    comparisons.data.comparisons.flatMap((comparison) =>
      comparison.includedTransactions
        .filter((transaction) => transaction.upcoming)
        .map((transaction) => transaction.id),
    ),
  );

  return (
    <div className={styles.list}>
      <UpcomingNotice
        includesUpcoming={comparisons.data.includesUpcoming}
        upcomingCount={upcomingSources.size}
      />
      {comparisons.data.comparisons.map((comparison) => (
        <ComparisonCard
          assetCode={comparisons.data!.assetCode}
          comparison={comparison}
          key={comparison.targetId}
        />
      ))}
    </div>
  );
}
