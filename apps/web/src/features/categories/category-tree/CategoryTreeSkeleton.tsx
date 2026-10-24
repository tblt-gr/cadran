import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './CategoryTree.module.css';

const ROWS = 6;

/** Mirrors CategoryTree and CategoryTreeNode element for element: only the row values are pending. */
export function CategoryTreeSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" data-testid="category-page-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <ul aria-label={t('categories.list.caption')} className={styles.branch}>
        {Array.from({ length: ROWS }, (_, row) => (
          <li className={styles.node} key={row}>
            <div className={styles.row}>
              <span aria-hidden="true" className={styles.toggleSpacer} />
              <div className={styles.summary}>
                <SkeletonBlock className={styles.skeletonIdentity} />
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
