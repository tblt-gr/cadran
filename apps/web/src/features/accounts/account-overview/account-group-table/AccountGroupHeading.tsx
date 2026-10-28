import type { AccountOverviewGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { GroupMarker } from '@/features/accounts/group-marker/GroupMarker';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import styles from './AccountGroupHeading.module.css';

interface AccountGroupHeadingProps {
  headingId: string;
  /** Null while the overview is being read: only the values are blocks. */
  group: AccountOverviewGroup | null;
  sole?: boolean;
}

/**
 * Name, exact total and share of eligible net worth of one table. In parent mode the total is a
 * roll-up of the subtree; in sub-group mode the name carries its parent and the total is direct.
 */
export function AccountGroupHeading({ group, headingId, sole = false }: AccountGroupHeadingProps) {
  const { t } = useTranslation();
  const name =
    group === null
      ? null
      : group.groupId === null
        ? t(sole ? 'accounts.overview.sole' : 'accounts.overview.ungrouped')
        : group.label;

  return (
    <header className={styles.heading}>
      <h2 className={styles.title} id={headingId}>
        <GroupMarker color={group?.resolvedColor} pending={group === null} />
        <span>{name ?? <SkeletonBlock className={styles.nameSkeleton} />}</span>
        {group?.parentLabel ? (
          <>
            {' '}
            <small className={styles.parent}>({group.parentLabel})</small>
          </>
        ) : null}
      </h2>
      <dl className={styles.figures}>
        <div>
          <dt>{t('accounts.overview.total')}</dt>
          <dd>
            {group === null ? (
              <SkeletonBlock className={styles.figureSkeleton} />
            ) : (
              <NetWorthFigure amount={group.total} reason={group.totalReason} />
            )}
          </dd>
        </div>
        <div>
          <dt>{t('accounts.fields.share')}</dt>
          <dd>
            {group === null ? (
              <SkeletonBlock className={styles.figureSkeleton} />
            ) : (
              <ShareCell share={group.share} />
            )}
          </dd>
        </div>
      </dl>
    </header>
  );
}
