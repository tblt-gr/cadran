import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './FacetCount.module.css';

interface FacetCountProps {
  /** Transactions the option would keep; `null` while the first answer is still on its way. */
  count: number | null;
}

/**
 * The number of transactions an option of the filter column would keep, right-aligned after its
 * label. The digits are hidden from assistive technology and replaced by a sentence, so the
 * checkbox reads `Comptabilisée, 184 transactions`. Rendered inside the option's `label`, it
 * flows as a sibling of the label text.
 */
export function FacetCount({ count }: FacetCountProps) {
  const { t } = useTranslation();

  return (
    <>
      <span aria-hidden="true" className={styles.count}>
        {count === null ? <SkeletonBlock className={styles.skeleton} /> : count}
      </span>
      {count === null ? null : (
        // The comma is the pause between the label and the count in the accessible name.
        <span className="sr-only">, {t('transactions.filters.facetCount', { count })}</span>
      )}
    </>
  );
}
