import type { TransactionSplit } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import { formatAmount } from '@/lib/decimal';
import styles from './TransactionCategories.module.css';

/** Beyond this many rows, a split shows its first rows and counts the rest. */
const VISIBLE_SPLITS = 3;

interface TransactionCategoriesProps {
  splits: TransactionSplit[];
}

/**
 * The category cell of a transaction row. A split transaction lists its categories
 * with their share, so the row never passes for one category when it holds several.
 */
export function TransactionCategories({ splits }: TransactionCategoriesProps) {
  const { i18n, t } = useTranslation();
  const [first] = splits;

  if (first === undefined) {
    return t('transactions.list.toCategorise');
  }

  if (splits.length === 1) {
    return (
      <CategoryIdentity
        color={first.categoryColor}
        icon={first.categoryIcon}
        label={first.categoryLabel}
      />
    );
  }

  const visible = splits.length > VISIBLE_SPLITS ? splits.slice(0, VISIBLE_SPLITS - 1) : splits;
  const hidden = splits.length - visible.length;

  return (
    <div className={styles.split}>
      <span className={styles.count}>
        {t('transactions.list.splitCount', { count: splits.length })}
      </span>
      <ul className={styles.rows}>
        {visible.map((split) => (
          <li key={split.id}>
            <CategoryIdentity
              color={split.categoryColor}
              icon={split.categoryIcon}
              label={split.categoryLabel}
            />
            {/* The transaction amount beside it already carries the sign. */}
            <span className={styles.amount}>
              {formatAmount(
                split.amount.value.replace(/^-/, ''),
                split.amount.assetCode,
                i18n.language,
              )}
            </span>
          </li>
        ))}
      </ul>
      {hidden > 0 ? (
        <span className={styles.more}>{t('transactions.list.moreSplits', { count: hidden })}</span>
      ) : null}
    </div>
  );
}
