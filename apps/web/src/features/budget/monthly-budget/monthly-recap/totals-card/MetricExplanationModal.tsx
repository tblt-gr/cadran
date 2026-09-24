import type { MonthlyKpiSourceTransaction, MonthlyRecapMetric } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
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

  return (
    <Modal close={close} title={t('reports.explainKpi', { kpi: label })}>
      {kpi && explanation.isPending ? <p role="status">{t('reports.explanationLoading')}</p> : null}
      {kpi && (explanation.isError || !explanation.data) ? (
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
        <SourceTransactions transactions={transactions} />
      </section>
    </Modal>
  );
}
