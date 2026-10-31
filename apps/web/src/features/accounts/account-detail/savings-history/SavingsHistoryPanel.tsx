import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SavingsHistoryChart } from './SavingsHistoryChart';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { ChartPeriodControl } from '@/components/ui/chart-period-control/ChartPeriodControl';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { chartPeriodFrom, type ChartPeriod } from '@/lib/chartPeriodRange';
import { SavingsHistoryTable } from './SavingsHistoryTable';
import { useSavingsHistory } from './useSavingsHistory';
import styles from './SavingsHistoryPanel.module.css';

// An explicit range spans at most 366 elapsed days; Tout omits `from` and the API resolves it.
const SAVINGS_PERIODS: readonly ChartPeriod[] = ['ALL', 'LAST_12_MONTHS', 'YTD'];

export function SavingsHistoryPanel({ accountId, today }: { accountId: string; today: string }) {
  const { t } = useTranslation();
  const id = useId();
  const [period, setPeriod] = useState<ChartPeriod>('LAST_12_MONTHS');
  const [tableOpen, setTableOpen] = useState(false);
  const from = chartPeriodFrom(period, today);
  const history = useSavingsHistory(accountId, from, today);
  const points = history.data?.points;
  const pending = history.isPending;
  return (
    <section
      className={`card ${styles.card}`}
      aria-labelledby={id}
      aria-busy={pending || history.isPlaceholderData}
    >
      {pending ? <SkeletonStatus>{t('accounts.history.loading')}</SkeletonStatus> : null}
      <div className={styles.header}>
        <h3 id={id}>{t('accounts.history.title')}</h3>
        <ChartPeriodControl
          label={t('accounts.history.period')}
          disabled={pending}
          periods={SAVINGS_PERIODS}
          value={period}
          onChange={setPeriod}
        />
      </div>
      {history.data?.truncated ? (
        <p className={styles.notice}>{t('accounts.history.truncated')}</p>
      ) : null}
      {history.isError ? (
        <div role="alert">
          <p>{t('accounts.history.error')}</p>
          <button className="secondary-action" type="button" onClick={() => void history.refetch()}>
            {t('foundation.retry')}
          </button>
        </div>
      ) : null}
      {pending || points ? (
        <>
          {pending ? (
            <CurveChartSkeleton />
          ) : history.data?.hasHistory ? (
            <SavingsHistoryChart points={points!} />
          ) : (
            <p role="status">{t('accounts.history.empty')}</p>
          )}
          <Disclosure
            title={t('accounts.history.showTable')}
            disabled={pending}
            open={tableOpen}
            onToggle={setTableOpen}
          >
            <SavingsHistoryTable points={pending ? null : points!} />
          </Disclosure>
        </>
      ) : null}
    </section>
  );
}
