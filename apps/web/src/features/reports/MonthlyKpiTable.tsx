import type {
  ExplainMonthlyKpiData,
  MonthlyProjection,
  MonthlyProjectionMetric,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { useMonthlyKpiExplanation } from './useMonthlyReport';
import styles from './ReportsPage.module.css';

const kpis: ExplainMonthlyKpiData['path']['kpi'][] = [
  'cashIncome',
  'nonCashBenefits',
  'budgetExpenses',
  'uncategorizedExpenses',
  'budgetSurplus',
  'savingsTransfers',
  'cashSavingsRate',
  'beginningNetWorth',
  'endNetWorth',
  'netWorthDelta',
] as const;

function MetricValue({ metric, rate }: { metric: MonthlyProjectionMetric; rate: boolean }) {
  const { i18n, t } = useTranslation();
  if (metric.value === null) {
    return <span>{t('reports.notCalculable', { reason: metric.reason ?? '' })}</span>;
  }
  if (rate && metric.assetCode === null)
    return <MoneyValue value={formatRatioPercentage(metric.value, i18n.language)} />;
  if (metric.assetCode === null)
    return <span>{t('reports.notCalculable', { reason: metric.reason ?? '' })}</span>;
  return <MoneyValue value={formatAmount(metric.value, metric.assetCode, i18n.language)} />;
}

function Explanation({ month, kpi }: { month: string; kpi: ExplainMonthlyKpiData['path']['kpi'] }) {
  const explanation = useMonthlyKpiExplanation(month, kpi);
  const { t } = useTranslation();
  if (explanation.isPending) return <p role="status">{t('reports.explanationLoading')}</p>;
  if (explanation.isError || !explanation.data)
    return <p role="alert">{t('reports.explanationError')}</p>;
  const value = explanation.data;
  return (
    <dl
      id={`monthly-kpi-explanation-${kpi}`}
      className={styles.explanation}
      aria-label={t('reports.explanationLabel')}
    >
      <dt>{t('reports.formula')}</dt>
      <dd>{value.formula}</dd>
      <dt>{t('reports.scope')}</dt>
      <dd>{value.scope}</dd>
      <dt>{t('reports.period')}</dt>
      <dd>
        {value.period.start} — {value.period.end}
      </dd>
      <dt>{t('reports.freshness')}</dt>
      <dd>{value.freshness}</dd>
      <dt>{t('reports.quality')}</dt>
      <dd>{value.quality}</dd>
      <dt>{t('reports.transactionSources')}</dt>
      <dd>
        {value.sourceTransactionIds.length ? (
          <ul>
            {value.sourceTransactionIds.map((id) => (
              <li key={id}>{id}</li>
            ))}
          </ul>
        ) : (
          t('reports.noSources')
        )}
      </dd>
      <dt>{t('reports.accountSources')}</dt>
      <dd>
        {value.sourceAccountIds.length ? (
          <ul>
            {value.sourceAccountIds.map((id) => (
              <li key={id}>{id}</li>
            ))}
          </ul>
        ) : (
          t('reports.noSources')
        )}
      </dd>
    </dl>
  );
}

export function MonthlyKpiTable({ report }: { report: MonthlyProjection }) {
  const { t } = useTranslation();
  const [opened, setOpened] = useState<ExplainMonthlyKpiData['path']['kpi'] | null>(null);
  return (
    <section className={`card ${styles.panel}`} aria-labelledby="monthly-kpis-title">
      <h2 id="monthly-kpis-title">{t('reports.kpis')}</h2>
      <div className={styles.tableWrap}>
        <table>
          <caption className="sr-only">{t('reports.kpis')}</caption>
          <thead>
            <tr>
              <th>{t('reports.metric')}</th>
              <th>{t('reports.value')}</th>
              <th>{t('reports.quality')}</th>
              <th>{t('reports.explain')}</th>
            </tr>
          </thead>
          <tbody>
            {kpis.map((kpi) => (
              <tr key={kpi}>
                <th scope="row">{t(`reports.metrics.${kpi}`)}</th>
                <td>
                  <MetricValue metric={report[kpi]} rate={kpi === 'cashSavingsRate'} />
                </td>
                <td>{report.quality}</td>
                <td>
                  <button
                    className="secondary-action"
                    type="button"
                    aria-expanded={opened === kpi}
                    aria-controls={`monthly-kpi-explanation-${kpi}`}
                    aria-label={t('reports.explainKpi', { kpi: t(`reports.metrics.${kpi}`) })}
                    onClick={() => setOpened(opened === kpi ? null : kpi)}
                  >
                    {t('reports.explain')}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {opened ? <Explanation month={report.month} kpi={opened} /> : null}
    </section>
  );
}
