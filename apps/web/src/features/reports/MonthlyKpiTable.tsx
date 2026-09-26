import type {
  ExplainMonthlyKpiData,
  MonthlyProjection,
  MonthlyProjectionMetric,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { useMonthlyKpiExplanation } from './useMonthlyReport';
import styles from './ReportsPage.module.css';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

const kpis: ExplainMonthlyKpiData['path']['kpi'][] = [
  'cashIncome',
  'nonCashBenefits',
  'benefitSpending',
  'budgetExpenses',
  'uncategorizedExpenses',
  'budgetSurplus',
  'savingsTransfers',
  'cashSavingsRate',
  'savingsInflows',
  'savingsWithdrawals',
  'netSavingsTransfers',
  'netSavingsRate',
  'beginningNetWorth',
  'endNetWorth',
  'netWorthDelta',
] as const;

function NotCalculable({ reason }: { reason: string | null }) {
  const { t } = useTranslation();
  return <EmptyValue label={t('states.notCalculable.label')} reason={reason} />;
}

function MetricValue({ metric, rate }: { metric: MonthlyProjectionMetric; rate: boolean }) {
  const { i18n } = useTranslation();
  if (metric.value === null) return <NotCalculable reason={metric.reason} />;
  if (rate && metric.assetCode === null)
    return <MoneyValue value={formatRatioPercentage(metric.value, i18n.language)} />;
  if (metric.assetCode === null) return <NotCalculable reason={metric.reason} />;
  return <MoneyValue value={formatAmount(metric.value, metric.assetCode, i18n.language)} />;
}

function Explanation({ month, kpi }: { month: string; kpi: ExplainMonthlyKpiData['path']['kpi'] }) {
  const explanation = useMonthlyKpiExplanation(month, kpi);
  const { i18n, t } = useTranslation();
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
        {value.sourceTransactions.length ? (
          <div
            className={styles.sourceTableWrap}
            role="region"
            aria-label={t('reports.transactionSources')}
            tabIndex={0}
          >
            <table className={styles.sourceTable}>
              <caption className="sr-only">{t('reports.transactionSources')}</caption>
              <thead>
                <tr>
                  <th>{t('reports.sourceId')}</th>
                  <th>{t('reports.sourceDate')}</th>
                  <th>{t('reports.sourceLabel')}</th>
                  <th>{t('reports.sourceAmount')}</th>
                  <th>{t('reports.sourceState')}</th>
                </tr>
              </thead>
              <tbody>
                {value.sourceTransactions.map((transaction) => (
                  <tr key={transaction.id}>
                    <td>{transaction.id}</td>
                    <td>{formatCalendarNumericDay(transaction.bookedOn)}</td>
                    <td>{transaction.label}</td>
                    <td>
                      <MoneyValue
                        value={formatAmount(
                          transaction.amount.value,
                          transaction.amount.assetCode,
                          i18n.language,
                        )}
                      />
                    </td>
                    <td>{transaction.state}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
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
      <dt>{t('reports.transferSources')}</dt>
      <dd>
        {value.sourceTransferIds.length ? (
          <ul>
            {value.sourceTransferIds.map((id) => (
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
            </tr>
          </thead>
          <tbody>
            {kpis.map((kpi) => (
              <tr key={kpi}>
                <th scope="row">{t(`reports.metrics.${kpi}`)}</th>
                <td>
                  <span className={styles.value}>
                    <MetricValue
                      metric={report[kpi]}
                      rate={kpi === 'cashSavingsRate' || kpi === 'netSavingsRate'}
                    />
                    <InfoButton
                      aria-controls={`monthly-kpi-explanation-${kpi}`}
                      aria-expanded={opened === kpi}
                      label={t('reports.explainKpi', { kpi: t(`reports.metrics.${kpi}`) })}
                      onClick={() => setOpened(opened === kpi ? null : kpi)}
                    />
                  </span>
                </td>
                <td>{report.quality}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {opened ? <Explanation month={report.month} kpi={opened} /> : null}
    </section>
  );
}
