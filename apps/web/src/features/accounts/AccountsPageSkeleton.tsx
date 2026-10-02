import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './account-list/AccountList.module.css';
export function AccountsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  const headings = [
    t('accounts.fields.label'),
    t('accounts.fields.kind'),
    t('accounts.fields.assetCode'),
    t('accounts.fields.valuationMode'),
    t('accounts.fields.balance'),
    t('accounts.fields.contribution'),
    t('accounts.fields.share'),
    t('accounts.list.status'),
    t('accounts.list.actions'),
  ];
  return (
    <section
      aria-busy="true"
      className={`card ${styles.panel}`}
      data-testid="accounts-page-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.list.caption')}</caption>
          <thead>
            <tr>
              {headings.map((heading) => (
                <th key={heading} scope="col">
                  {heading}
                </th>
              ))}
            </tr>
          </thead>
          <SkeletonTableRows columns={9} rows={6} />
        </table>
      </div>
    </section>
  );
}
