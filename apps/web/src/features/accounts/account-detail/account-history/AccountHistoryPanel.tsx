import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountHistoryChart } from './AccountHistoryChart';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { ChartPeriodControl } from '@/components/ui/chart-period-control/ChartPeriodControl';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { ACCOUNT_PERIODS } from './accountHistoryPeriods';
import { chartPeriodFrom, type ChartPeriod } from '@/lib/chartPeriodRange';
import {
  historyKeys,
  showsManualPortfolioNotice,
  type HistoryAccount,
} from './accountHistoryLabels';
import { AccountHistoryTable } from './AccountHistoryTable';
import { useAccountHistory } from './useAccountHistory';
import styles from './AccountHistoryPanel.module.css';

export function AccountHistoryPanel({
  account,
  today,
}: {
  account: HistoryAccount & { id: string };
  today: string;
}) {
  const { t } = useTranslation();
  const id = useId();
  const [period, setPeriod] = useState<ChartPeriod>('LAST_12_MONTHS');
  const [tableOpen, setTableOpen] = useState(false);
  const from = chartPeriodFrom(period, today);
  const history = useAccountHistory(account.id, from, today);
  const points = history.data?.points;
  const keys = historyKeys(account);
  const pending = history.isPending;
  return (
    <section
      className={`card ${styles.card}`}
      aria-labelledby={id}
      aria-busy={pending || history.isPlaceholderData}
    >
      {pending ? <SkeletonStatus>{t('accounts.history.loading')}</SkeletonStatus> : null}
      <div className={styles.header}>
        <h3 id={id}>{t(keys.title)}</h3>
        <ChartPeriodControl
          label={t('accounts.history.period')}
          disabled={pending}
          periods={ACCOUNT_PERIODS}
          value={period}
          onChange={setPeriod}
        />
      </div>
      {showsManualPortfolioNotice(account) ? (
        <p className={styles.notice}>{t('accounts.history.manualPortfolioNotice')}</p>
      ) : null}
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
            <AccountHistoryChart account={account} points={points!} />
          ) : (
            <p role="status">{t('accounts.history.empty')}</p>
          )}
          <Disclosure
            title={t('accounts.history.showTable')}
            disabled={pending}
            open={tableOpen}
            onToggle={setTableOpen}
          >
            <AccountHistoryTable account={account} points={pending ? null : points!} />
          </Disclosure>
        </>
      ) : null}
    </section>
  );
}
