import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SavingsHistoryChart } from './SavingsHistoryChart';
import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { historyFrom } from '@/features/portfolio/portfolio-account/value-history-card/valueHistoryRange';
import { SavingsHistoryTable } from './SavingsHistoryTable';
import { useSavingsHistory } from './useSavingsHistory';
import styles from './SavingsHistoryPanel.module.css';

export function SavingsHistoryPanel({ accountId, today }: { accountId: string; today: string }) {
  const { t } = useTranslation();
  const id = useId();
  const [period, setPeriod] = useState<'12' | 'year'>('12');
  const [tableOpen, setTableOpen] = useState(false);
  const from = period === 'year' ? `${today.slice(0, 4)}-01-01` : historyFrom(today, '12')!;
  const history = useSavingsHistory(accountId, from, today);
  const points = history.data?.points;
  const pending = history.isPending;
  return (
    <section className={`card ${styles.card}`} aria-labelledby={id} aria-busy={pending}>
      {pending ? <SkeletonStatus>{t('accounts.history.loading')}</SkeletonStatus> : null}
      <div className={styles.header}>
        <h3 id={id}>{t('accounts.history.title')}</h3>
        <SegmentedControl
          label={t('accounts.history.period')}
          disabled={pending}
          options={[
            { value: '12', label: t('accounts.history.rolling') },
            { value: 'year', label: t('accounts.history.year') },
          ]}
          value={period}
          onChange={setPeriod}
        />
      </div>
      <p className={styles.notice}>{t('accounts.history.description')}</p>
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
