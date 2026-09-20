import { readBudgetComparisons, type BudgetComparison } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { authApiOptions } from '@/features/auth/apiOptions';
import { budgetRequestError } from '@/features/budget/budgetError';
import { formatAmount } from '@/lib/decimal';
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
          {t(`budget.scopeTypes.${comparison.scopeType}`)} · {comparison.scopeId}
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
            <Figure assetCode={assetCode} language={i18n.language} value={comparison.variance} />
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
      <p className={styles.policy}>{comparison.policy}</p>
      <details className={styles.sources}>
        <summary>
          {t('budget.comparisons.sources', { count: comparison.includedTransactionIds.length })}
        </summary>
        <ul>
          {comparison.includedTransactionIds.map((transactionId) => (
            <li key={transactionId}>{transactionId}</li>
          ))}
        </ul>
      </details>
    </article>
  );
}

/** Server-owned comparison figures: this component never derives financial values. */
export function BudgetComparisonsPanel({ planId }: { planId: string }) {
  const { t } = useTranslation();
  const comparisons = useQuery({
    queryKey: ['budget-comparisons', planId],
    queryFn: async ({ signal }) => {
      const result = await readBudgetComparisons({
        ...authApiOptions(),
        path: { id: planId },
        signal,
      });
      if (requestFailed(result)) throw budgetRequestError(result);
      return result.data!;
    },
    retry: false,
  });

  if (comparisons.isPending) {
    return (
      <p className={styles.state} role="status">
        {t('budget.comparisons.loading')}
      </p>
    );
  }
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

  return (
    <div className={styles.list}>
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
