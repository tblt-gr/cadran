import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import {
  SkeletonBlock,
  SkeletonButton,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import detailStyles from './plan-detail/PlanDetail.module.css';
import styles from './BudgetPage.module.css';

export function BudgetPageSkeleton({
  status,
  detail = false,
}: {
  status: string;
  detail?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.page} data-testid="budget-page-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <HeaderActions>
        <button aria-label={t('budget.addPlan')} className="primary-action" disabled type="button">
          <Icon name="add" size={18} />
          <span className={styles.actionLabel}>{t('budget.addPlan')}</span>
        </button>
      </HeaderActions>
      {detail ? (
        <>
          <section className={`card ${detailStyles.summary}`}>
            <div>
              <span>{t('budget.columns.currency')}</span>
              <SkeletonBlock className={styles.skeletonDescription} />
            </div>
            <div>
              <span>{t('budget.columns.state')}</span>
              <SkeletonBlock className={styles.skeletonDescription} />
            </div>
            <div className={detailStyles.summaryActions}>
              <SkeletonButton />
            </div>
          </section>
          <section className={detailStyles.targets}>
            <div className={detailStyles.targetHeading}>
              <div>
                <h3>{t('budget.targets.title')}</h3>
                <p>{t('budget.targets.description')}</p>
              </div>
              <button className="secondary-action" disabled type="button">
                {t('budget.targets.add')}
              </button>
            </div>
            <div className={detailStyles.tableWrap}>
              <table aria-hidden="true" className={detailStyles.table}>
                <thead>
                  <tr>
                    <th>{t('budget.columns.scope')}</th>
                    <th>{t('budget.columns.value')}</th>
                    <th>{t('budget.columns.resolved')}</th>
                    <th>{t('budget.columns.warning')}</th>
                    <th>
                      <span className="sr-only">{t('actions.more')}</span>
                    </th>
                  </tr>
                </thead>
                <SkeletonTableRows columns={5} rows={4} />
              </table>
            </div>
          </section>
        </>
      ) : (
        <div
          className={styles.tableScroll}
          role="region"
          aria-label={t('routes.budgetPlans')}
          tabIndex={0}
        >
          <table aria-hidden="true" className={styles.table}>
            <thead>
              <tr>
                <th>{t('budget.columns.period')}</th>
                <th>{t('budget.columns.currency')}</th>
                <th>{t('budget.columns.state')}</th>
                <th>
                  <span className="sr-only">{t('actions.more')}</span>
                </th>
              </tr>
            </thead>
            <SkeletonTableRows columns={4} rows={4} />
          </table>
        </div>
      )}
    </div>
  );
}
