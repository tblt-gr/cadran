import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useSession } from '@/features/auth/useSession';
import { AccountRecap } from './account-recap/AccountRecap';
import { RecapInfo } from './recap-info/RecapInfo';
import { RecapPreferencesModal } from './recap-preferences/RecapPreferencesModal';
import { TotalsCard } from './totals-card/TotalsCard';
import { useMonthlyRecap, useRecapPreferences } from './useMonthlyRecap';
import { WealthCard } from './wealth-card/WealthCard';
import { MonthlyRecapSkeleton, TotalsCardPlaceholder } from './MonthlyRecapSkeleton';
import styles from './MonthlyRecap.module.css';

export function MonthlyRecap({ month }: { month: string }) {
  const { t } = useTranslation();
  const recap = useMonthlyRecap(month);
  const preferences = useRecapPreferences();
  const session = useSession();
  const [editingPreferences, setEditingPreferences] = useState(false);
  const owner = session.data?.workspace?.role === 'OWNER';
  if (recap.isPending && recap.data === undefined)
    return <MonthlyRecapSkeleton status={t('budget.monthly.recap.loading')} />;
  if (recap.isError || !recap.data)
    return (
      <aside className={`card ${styles.state}`} role="alert">
        <h3>{t('budget.monthly.recap.error.title')}</h3>
        <p>{t('budget.monthly.recap.error.description')}</p>
        <button className="secondary-action" onClick={() => void recap.refetch()} type="button">
          {t('foundation.retry')}
        </button>
      </aside>
    );
  const data = recap.data;
  return (
    <aside aria-label={t('budget.monthly.recap.label')} className={styles.recap}>
      {data.state === 'EMPTY' ? (
        <p className={styles.pending}>{t('budget.monthly.recap.empty')}</p>
      ) : null}
      <div className={styles.wealth}>
        <WealthCard netWorth={data.netWorth} provisional={data.provisional} />
      </div>
      <div className={styles.totals}>
        {preferences.isPending ? (
          <div aria-busy="true">
            <SkeletonStatus>{t('budget.monthly.recap.preferences.loading')}</SkeletonStatus>
            <TotalsCardPlaceholder />
          </div>
        ) : preferences.isError || !preferences.data ? (
          <section className={`card ${styles.preferenceState}`} role="alert">
            <p>{t('budget.monthly.recap.preferences.error')}</p>
            <button
              className="secondary-action"
              onClick={() => void preferences.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          </section>
        ) : (
          <TotalsCard
            info={<RecapInfo pending={data.state === 'PENDING'} policy={data.metricPolicy} />}
            month={month}
            onConfigure={() => setEditingPreferences(true)}
            owner={owner}
            totals={data.totals}
            visibleAxes={preferences.data.visibleAxes}
            visibleCategoryIds={preferences.data.visibleCategoryIds}
          />
        )}
      </div>
      <div className={styles.accounts}>
        <AccountRecap
          accounts={data.accounts}
          currentAsOf={data.currentAsOf}
          groups={data.groups}
          previousAsOf={data.previousAsOf}
        />
      </div>
      {editingPreferences && preferences.data ? (
        <RecapPreferencesModal
          close={() => setEditingPreferences(false)}
          key={preferences.data.version}
          onReload={() => void preferences.refetch()}
          preferences={preferences.data}
          representedCategoryIds={data.totals.categories.map((category) => category.id)}
        />
      ) : null}
    </aside>
  );
}
