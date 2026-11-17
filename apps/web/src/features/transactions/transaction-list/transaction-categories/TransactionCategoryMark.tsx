import type { TransactionSplit } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ColorDot } from '@/components/ui/color-dot/ColorDot';
import { Icon } from '@/components/ui/icon/Icon';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import styles from './TransactionCategoryMark.module.css';

interface TransactionCategoryMarkProps {
  splits: TransactionSplit[];
  /** A transfer is neutral: it gets its own glyph instead of a "to categorise" one. */
  transfer: boolean;
}

/**
 * The category of a row reduced to its glyph, for a phone where the label has no room. One category
 * draws its own glyph or dot; several draw a stack; none draws a neutral glyph. The name stays
 * available to assistive technology and in the detail dialog.
 */
export function TransactionCategoryMark({ splits, transfer }: TransactionCategoryMarkProps) {
  const { t } = useTranslation();
  const [first] = splits;

  if (first === undefined) {
    return (
      <span
        aria-label={
          transfer ? t('transactions.natures.TRANSFER') : t('transactions.list.toCategorise')
        }
        className={styles.mark}
        role="img"
      >
        <Icon name={transfer ? 'transfer' : 'categories'} size={16} />
      </span>
    );
  }

  if (splits.length > 1) {
    return (
      <span
        aria-label={t('transactions.list.splitCount', { count: splits.length })}
        className={styles.mark}
        role="img"
      >
        <Icon name="layers" size={16} />
      </span>
    );
  }

  const artwork = categoryIcon(first.categoryIcon);
  const color = canonicalCategoryColor(first.categoryColor);
  return (
    <span aria-label={first.categoryLabel} className={styles.mark} role="img">
      {artwork ? (
        <svg aria-hidden="true" className={styles.glyph} focusable="false" viewBox="0 0 24 24">
          <path d={artwork.path} stroke={color ?? undefined} />
        </svg>
      ) : (
        <ColorDot color={color} />
      )}
    </span>
  );
}
