import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { SortableHeader } from '@/components/ui/sortable-header/SortableHeader';
import { useTranslation } from 'react-i18next';
import { AllocationChartsSkeleton } from '@/features/dashboard/allocation-charts/AllocationChartsSkeleton';
import pageStyles from './AccountGroupsPage.module.css';
import styles from './group-list/GroupList.module.css';

const noop = () => undefined;

/** Mirrors the allocation card and GroupList element for element: only values are pending. */
export function AccountGroupsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  const header = (column: string, label: string) => (
    <SortableHeader column={column} current="label" direction="asc" disabled onSort={noop}>
      {label}
    </SortableHeader>
  );

  return (
    <>
      <SkeletonStatus>{status}</SkeletonStatus>
      <section aria-hidden="true" className={`card ${pageStyles.allocation}`}>
        <div>
          <AllocationChartsSkeleton />
        </div>
      </section>
      <div
        aria-busy="true"
        className={`card ${styles.panel}`}
        data-testid="account-groups-skeleton"
      >
        <div className={styles.tableScroll}>
          <table>
            <caption className="sr-only">{t('accountGroups.list.caption')}</caption>
            <thead>
              <tr>
                {header('label', t('accountGroups.fields.label'))}
                {header('parent', t('accountGroups.fields.parent'))}
                {header('total', t('accountGroups.fields.total'))}
                {header('share', t('accountGroups.fields.share'))}
                {header('status', t('accountGroups.list.status'))}
                <th scope="col">
                  <span className="sr-only">{t('accountGroups.list.actions')}</span>
                </th>
              </tr>
            </thead>
            <SkeletonTableRows columns={6} rows={4} />
          </table>
        </div>
      </div>
    </>
  );
}
