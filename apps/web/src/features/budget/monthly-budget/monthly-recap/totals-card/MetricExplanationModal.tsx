import type { MonthlyKpiSourceTransaction, MonthlyRecapMetric } from '@cadran/api-client';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useMonthlyKpiExplanation } from '@/features/reports/useMonthlyReport';
import { formatAmount } from '@/lib/decimal';
import styles from './MetricExplanationModal.module.css';

interface MetricExplanationModalProps {
  close: () => void;
  label: string;
  month: string;
  sourceTransactions?: MonthlyKpiSourceTransaction[];
  kpi: MonthlyRecapMetric['kpi'];
}

function SourceTransactions({ transactions }: { transactions: MonthlyKpiSourceTransaction[] }) {
  const { i18n, t } = useTranslation();

  if (transactions.length === 0) return <p>{t('reports.noSources')}</p>;

  return (
    <ul className={styles.transactions}>
      {transactions.map((transaction) => (
        <li key={transaction.id}>
          <span>{transaction.label}</span>
          <MoneyValue
            value={formatAmount(
              transaction.amount.value,
              transaction.amount.assetCode,
              i18n.language,
            )}
          />
        </li>
      ))}
    </ul>
  );
}

/** Mirrors the explanation list: labels real, values pending. */
function ExplanationSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <>
      <SkeletonStatus>{status}</SkeletonStatus>
      <dl aria-busy="true" className={styles.details} data-testid="metric-explanation-skeleton">
        {(['reports.formula', 'reports.period', 'reports.quality'] as const).map((key) => (
          <Fragment key={key}>
            <dt>{t(key)}</dt>
            <dd>
              <SkeletonBlock className={styles.skeletonLabel} />
            </dd>
          </Fragment>
        ))}
      </dl>
    </>
  );
}

export function MetricExplanationModal({
  close,
  kpi,
  label,
  month,
  sourceTransactions,
}: MetricExplanationModalProps) {
  const { t } = useTranslation();
  const explanation = useMonthlyKpiExplanation(month, kpi);
  const transactions = kpi
    ? (explanation.data?.sourceTransactions ?? [])
    : (sourceTransactions ?? []);

  const pending = Boolean(kpi) && explanation.isPending;

  return (
    <Modal close={close} title={t('reports.explainKpi', { kpi: label })}>
      {pending ? (
        <ExplanationSkeleton status={t('reports.explanationLoading')} />
      ) : kpi && (explanation.isError || !explanation.data) ? (
        <p role="alert">{t('reports.explanationError')}</p>
      ) : null}
      {kpi && explanation.data ? (
        <dl className={styles.details}>
          <dt>{t('reports.formula')}</dt>
          <dd>{explanation.data.formula}</dd>
          <dt>{t('reports.period')}</dt>
          <dd>
            {explanation.data.period.start} — {explanation.data.period.end}
          </dd>
          <dt>{t('reports.quality')}</dt>
          <dd>{explanation.data.quality}</dd>
        </dl>
      ) : null}
      <section aria-labelledby="monthly-recap-source-transactions">
        <h3 id="monthly-recap-source-transactions">{t('reports.transactionSources')}</h3>
        {pending ? (
          <ul aria-hidden="true" className={styles.transactions}>
            {Array.from({ length: 3 }, (_, index) => (
              <li key={index}>
                <SkeletonBlock className={styles.skeletonLabel} />
                <SkeletonBlock className={styles.skeletonAmount} />
              </li>
            ))}
          </ul>
        ) : (
          <SourceTransactions transactions={transactions} />
        )}
      </section>
    </Modal>
  );
}
