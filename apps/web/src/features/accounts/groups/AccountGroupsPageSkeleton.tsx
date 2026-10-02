import {
  SkeletonChartArea,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import pageStyles from './AccountGroupsPage.module.css';
import styles from './group-list/GroupList.module.css';

export function AccountGroupsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <>
      <SkeletonStatus>{status}</SkeletonStatus>
      <section aria-hidden="true" className={`card ${pageStyles.allocation}`}>
        <SkeletonChartArea />
      </section>
      <section
        aria-busy="true"
        className={`card ${styles.panel}`}
        data-testid="account-groups-skeleton"
      >
        <div className={styles.tableScroll}>
          <table>
            <thead>
              <tr>
                <th scope="col">{t('accountGroups.fields.label')}</th>
                <th scope="col">{t('accountGroups.fields.parent')}</th>
                <th scope="col">{t('accountGroups.fields.total')}</th>
                <th scope="col">{t('accountGroups.fields.share')}</th>
                <th scope="col">{t('accountGroups.list.status')}</th>
                <th scope="col">
                  <span className="sr-only">{t('accountGroups.list.actions')}</span>
                </th>
              </tr>
            </thead>
            <SkeletonTableRows columns={6} rows={4} />
          </table>
        </div>
      </section>
    </>
  );
}
