import { MetricPolicyBadge } from '@/features/metric-policy/metric-policy-badge/MetricPolicyBadge';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSession } from '@/features/auth/useSession';
import { AccountRecap } from './account-recap/AccountRecap';
import { RecapPreferencesModal } from './recap-preferences/RecapPreferencesModal';
import { TotalsCard } from './totals-card/TotalsCard';
import { useMonthlyRecap, useRecapPreferences } from './useMonthlyRecap';
import { WealthCard } from './wealth-card/WealthCard';
import styles from './MonthlyRecap.module.css';

export function MonthlyRecap({ month }: { month: string }) {
  const { t } = useTranslation();
  const recap = useMonthlyRecap(month);
  const preferences = useRecapPreferences();
  const session = useSession();
  const [editingPreferences, setEditingPreferences] = useState(false);
  const owner = session.data?.workspace?.role === 'OWNER';
  if (recap.isPending)
    return (
      <aside aria-busy="true" className={`card ${styles.state}`} role="status">
        <h3>{t('budget.monthly.recap.loading')}</h3>
      </aside>
    );
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
      <p className={styles.policy}>
        <MetricPolicyBadge policy={data.metricPolicy} />
      </p>
      {data.state === 'PENDING' ? (
        <p className={styles.pending} role="status">
          {t('budget.monthly.recap.pending')}
        </p>
      ) : null}
      {data.state === 'EMPTY' ? (
        <p className={styles.pending}>{t('budget.monthly.recap.empty')}</p>
      ) : null}
      <WealthCard netWorth={data.netWorth} provisional={data.provisional} />
      {preferences.isPending ? (
        <section aria-busy="true" className={`card ${styles.preferenceState}`} role="status">
          {t('budget.monthly.recap.preferences.loading')}
        </section>
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
          month={month}
          onConfigure={() => setEditingPreferences(true)}
          owner={owner}
          totals={data.totals}
          visibleAxes={preferences.data.visibleAxes}
          visibleCategoryIds={preferences.data.visibleCategoryIds}
        />
      )}
      <AccountRecap
        accounts={data.accounts}
        currentAsOf={data.currentAsOf}
        groups={data.groups}
        previousAsOf={data.previousAsOf}
      />
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
