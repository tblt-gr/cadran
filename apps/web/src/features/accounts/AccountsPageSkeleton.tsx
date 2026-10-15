import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './account-list/AccountList.module.css';

/** Mirrors AccountList element for element: only the rows are pending. */
export function AccountsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={`card ${styles.panel}`} data-testid="accounts-page-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accounts.fields.label')}</th>
              <th scope="col">{t('accounts.fields.kind')}</th>
              <th scope="col">{t('accounts.fields.assetCode')}</th>
              <th scope="col">{t('accounts.fields.valuationMode')}</th>
              <th scope="col">{t('accounts.fields.balance')}</th>
              <th scope="col">{t('accounts.fields.contribution')}</th>
              <th scope="col">{t('accounts.fields.share')}</th>
              <th scope="col">{t('accounts.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('accounts.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={9} rows={6} />
        </table>
      </div>
    </div>
  );
}
