import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import { AccountValuationCell } from './account-valuation-cell/AccountValuationCell';
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
          <tbody aria-hidden="true">
            {Array.from({ length: 6 }, (_, row) => (
              <tr key={row}>
                <th scope="row">
                  <a className={styles.accountLink}>
                    <SkeletonBlock />
                  </a>
                </th>
                <td>
                  <SkeletonBlock />
                </td>
                <td>
                  <SkeletonBlock />
                </td>
                <td>
                  <SkeletonBlock />
                </td>
                <td>
                  <AccountValuationCell valuation={null} />
                </td>
                <td>
                  <SkeletonBlock />
                </td>
                <td>
                  <SkeletonBlock className={styles.shareSkeleton} />
                </td>
                <td>
                  <SkeletonBlock />
                </td>
                <td>
                  <SkeletonBlock />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
