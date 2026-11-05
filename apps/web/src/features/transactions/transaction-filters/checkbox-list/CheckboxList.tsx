import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { FacetCount } from '@/features/transactions/transaction-filters/facet-count/FacetCount';
import {
  optionCount,
  type OptionCounts,
} from '@/features/transactions/transaction-filters/facet-count/optionCount';
import styles from './CheckboxList.module.css';

interface CheckboxOption<T extends string> {
  value: T;
  label: string;
  /** Decoration before the label, such as a colour mark; never the only carrier of meaning. */
  leading?: ReactNode;
}

interface CheckboxListProps<T extends string> {
  /**
   * Transactions each option would keep, by option value: a missing value reads as 0 and its row
   * is muted, never disabled. `null` while the first answer is pending; absent where the list
   * shows no count at all.
   */
  counts?: OptionCounts;
  /** Accessible name of the group; the visible title belongs to the surrounding section. */
  legend: string;
  /** Rows shown before `Afficher plus`; a checked row past the limit always stays visible. */
  limit?: number;
  onChange: (next: T[]) => void;
  /** `null` while the options are loading: the rows render as skeleton labels. */
  options: readonly CheckboxOption<T>[] | null;
  /** Text shown when the loaded list holds no option at all. */
  emptyLabel?: string;
  value: readonly T[];
}

const SKELETON_ROWS = 3;

/**
 * One multi-valued filter as a plain list of checkboxes: every choice is readable at a glance,
 * with no popup to open. An empty selection means "no restriction".
 */
export function CheckboxList<T extends string>({
  counts,
  emptyLabel,
  legend,
  limit = Number.POSITIVE_INFINITY,
  onChange,
  options,
  value,
}: CheckboxListProps<T>) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);

  function toggle(option: T) {
    onChange(
      value.includes(option)
        ? value.filter((candidate) => candidate !== option)
        : [...value, option],
    );
  }

  const visible =
    options === null
      ? null
      : expanded
        ? options
        : options.filter((option, index) => index < limit || value.includes(option.value));
  const hiddenCount = options === null || visible === null ? 0 : options.length - visible.length;

  return (
    <fieldset className={styles.group}>
      <legend className="sr-only">{legend}</legend>
      {visible === null ? (
        <ul className={styles.list}>
          {Array.from({ length: SKELETON_ROWS }, (_, index) => (
            <li key={index}>
              <label className={styles.option}>
                <input disabled type="checkbox" />
                <SkeletonBlock className={styles.skeleton} />
                {counts === undefined ? null : <FacetCount count={null} />}
              </label>
            </li>
          ))}
        </ul>
      ) : visible.length === 0 ? (
        <p className={styles.empty}>{emptyLabel}</p>
      ) : (
        <ul className={styles.list}>
          {visible.map((option) => {
            const count = optionCount(counts, option.value);
            return (
              <li key={option.value}>
                <label className={styles.option} data-empty={count === 0 || undefined}>
                  <input
                    checked={value.includes(option.value)}
                    onChange={() => toggle(option.value)}
                    type="checkbox"
                  />
                  {option.leading}
                  <span className={styles.label}>{option.label}</span>
                  {count === undefined ? null : <FacetCount count={count} />}
                </label>
              </li>
            );
          })}
        </ul>
      )}
      {hiddenCount > 0 || (expanded && options !== null && options.length > limit) ? (
        <button
          aria-expanded={expanded}
          className={styles.more}
          onClick={() => setExpanded((open) => !open)}
          type="button"
        >
          {expanded
            ? t('transactions.filters.showLess')
            : t('transactions.filters.showMore', { count: hiddenCount })}
        </button>
      ) : null}
    </fieldset>
  );
}
