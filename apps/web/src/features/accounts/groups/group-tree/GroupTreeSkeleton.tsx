import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './GroupTree.module.css';

const ROWS = 6;

/** Mirrors GroupTree and GroupTreeNode element for element: only the row values are pending. */
export function GroupTreeSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" data-testid="account-groups-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <ul aria-label={t('accountGroups.list.caption')} className={styles.branch}>
        {Array.from({ length: ROWS }, (_, row) => (
          <li className={styles.node} key={row}>
            <div className={styles.row}>
              <span aria-hidden="true" className={styles.toggleSpacer} />
              <div className={styles.summary}>
                <p className={styles.identity}>
                  <SkeletonBlock className={styles.skeletonLabel} />
                </p>
                <p className={styles.meta}>
                  <SkeletonBlock className={styles.skeletonMeta} />
                </p>
              </div>
              <SkeletonBlock className={styles.skeletonAction} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
