import { useId } from 'react';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import { AccountList } from './account-list/AccountList';
import { AccountGroupHeading } from './account-overview/account-group-table/AccountGroupHeading';
import groupStyles from './account-overview/account-group-table/AccountGroupTable.module.css';
import overviewStyles from './account-overview/AccountOverview.module.css';

const noop = () => undefined;

/** Mirrors AccountOverview element for element: one table whose values are pending. */
export function AccountsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  const headingId = useId();

  return (
    <div aria-busy="true" className={overviewStyles.overview} data-testid="accounts-page-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <section aria-labelledby={headingId} className={groupStyles.group}>
        <AccountGroupHeading group={null} headingId={headingId} />
        <AccountList
          accounts={null}
          label={t('accounts.list.caption')}
          onArchive={noop}
          onEdit={noop}
          onMove={noop}
          onReconcile={noop}
          onRecordBalance={noop}
          onRules={noop}
        />
      </section>
    </div>
  );
}
