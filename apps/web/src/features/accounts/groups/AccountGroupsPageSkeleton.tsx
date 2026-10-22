import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { SortableHeader } from '@/components/ui/sortable-header/SortableHeader';
import { useTranslation } from 'react-i18next';
import styles from './group-list/GroupList.module.css';

const noop = () => undefined;

/** Mirrors GroupList element for element: only values are pending. */
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
                {header('status', t('accountGroups.list.status'))}
                <th scope="col">
                  <span className="sr-only">{t('accountGroups.list.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody aria-hidden="true">
              {Array.from({ length: 4 }, (_, index) => (
                <tr className={styles.groupRow} key={index}>
                  <th scope="row">
                    <span>
                      <SkeletonBlock />
                    </span>
                    <small>
                      <SkeletonBlock />
                    </small>
                  </th>
                  <td>
                    <SkeletonBlock />
                  </td>
                  <td>
                    <SkeletonBlock />
                  </td>
                  <td>
                    <div className={styles.rowActions}>
                      <SkeletonBlock />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  );
}
