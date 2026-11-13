import type { Category } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import { CategorySwatch } from '@/features/categories/category-swatch/CategorySwatch';
import { useCategoryIdentities } from '@/features/categories/useCategoryIdentities';
import { FacetCount } from '@/features/transactions/transaction-filters/facet-count/FacetCount';
import {
  optionCount,
  type OptionCounts,
} from '@/features/transactions/transaction-filters/facet-count/optionCount';
import { buildTree, type TreeNode } from '@/lib/tree/buildTree';
import styles from './CategoryCheckboxTree.module.css';

/** Top-level categories shown before `Afficher plus`; their sub-categories always follow them. */
const ROOT_LIMIT = 6;
const SKELETON_ROWS = 3;

interface CategoryCheckboxTreeProps {
  categoryIds: readonly string[];
  /**
   * Transactions each category would keep, by category id: a missing id reads as 0 and its row is
   * muted, never disabled. `null` while the first answer is pending; absent where the tree shows
   * no count. `Sans catégorie` and the sub-category switch never carry one.
   */
  counts?: OptionCounts;
  includeDescendants: boolean;
  onCategoryIdsChange: (next: string[]) => void;
  onIncludeDescendantsChange: (value: boolean) => void;
  onUncategorizedChange: (value: boolean) => void;
  /** Only transactions with no category: exclusive with picking categories. */
  uncategorized: boolean;
}

function bySortOrder(a: Category, b: Category): number {
  return a.sortOrder - b.sortOrder || a.label.localeCompare(b.label);
}

/**
 * The category filter as the category tree itself, one checkbox per category with its colour
 * mark, headed by `Sans catégorie` for the transactions still to categorise. The two are
 * exclusive: a transaction with no category matches no category, so checking one side clears
 * the other rather than producing an always-empty list. Archived categories stay out of the
 * tree unless already selected, so a filter restored from the URL can always be unchecked where
 * it was set.
 */
export function CategoryCheckboxTree({
  categoryIds,
  counts,
  includeDescendants,
  onCategoryIdsChange,
  onIncludeDescendantsChange,
  onUncategorizedChange,
  uncategorized,
}: CategoryCheckboxTreeProps) {
  const { t } = useTranslation();
  const identities = useCategoryIdentities();
  const [expanded, setExpanded] = useState(false);
  const legend = t('transactions.filters.sections.categories');

  function toggle(categoryId: string) {
    onCategoryIdsChange(
      categoryIds.includes(categoryId)
        ? categoryIds.filter((candidate) => candidate !== categoryId)
        : [...categoryIds, categoryId],
    );
  }

  function renderNodes(nodes: TreeNode<Category>[]) {
    return nodes.map(({ item, children }) => {
      const marked = Boolean(
        categoryIcon(item.effectiveIcon) || canonicalCategoryColor(item.effectiveColor),
      );
      const count = optionCount(counts, item.id);
      return (
        <li key={item.id}>
          <label className={styles.option} data-empty={count === 0 || undefined}>
            <input
              checked={categoryIds.includes(item.id)}
              onChange={() => toggle(item.id)}
              type="checkbox"
            />
            {/* The category's mark, as in the picker: its icon in its colour, else a dot. */}
            <span aria-hidden="true" className={styles.markSlot}>
              {marked ? (
                <CategorySwatch color={item.effectiveColor} icon={item.effectiveIcon} />
              ) : (
                <span className={`${styles.mark} ${styles.neutral}`} />
              )}
            </span>
            <span className={styles.label}>{item.label}</span>
            {count === undefined ? null : <FacetCount count={count} />}
          </label>
          {children.length > 0 ? (
            <ul className={`${styles.list} ${styles.children}`}>{renderNodes(children)}</ul>
          ) : null}
        </li>
      );
    });
  }

  let content;
  if (identities.isPending) {
    content = (
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
    );
  } else if (identities.isError) {
    content = (
      <p className={styles.status} role="alert">
        {t('transactions.filters.categoriesUnavailable')}{' '}
        <button className={styles.more} onClick={() => void identities.refetch()} type="button">
          {t('foundation.retry')}
        </button>
      </p>
    );
  } else {
    const shown = [...identities.data.values()]
      .filter((category) => category.archivedAt === null || categoryIds.includes(category.id))
      .sort(bySortOrder);
    const roots = buildTree(shown);
    const holdsSelection = (node: TreeNode<Category>): boolean =>
      categoryIds.includes(node.item.id) || node.children.some(holdsSelection);
    const visibleRoots = expanded
      ? roots
      : roots.filter((root, index) => index < ROOT_LIMIT || holdsSelection(root));
    const hiddenCount = roots.length - visibleRoots.length;

    content = (
      <>
        {roots.length === 0 ? (
          <p className={styles.status}>{t('transactions.filters.categoriesEmpty')}</p>
        ) : (
          <ul className={styles.list}>{renderNodes(visibleRoots)}</ul>
        )}
        {roots.length > ROOT_LIMIT && (expanded || hiddenCount > 0) ? (
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
      </>
    );
  }

  return (
    <fieldset className={styles.group}>
      <legend className="sr-only">{legend}</legend>
      <label className={`${styles.option} ${styles.uncategorized}`}>
        <input
          checked={uncategorized}
          onChange={(event) => onUncategorizedChange(event.target.checked)}
          type="checkbox"
        />
        <span aria-hidden="true" className={styles.markSlot}>
          <span className={`${styles.mark} ${styles.neutral}`} />
        </span>
        <span className={styles.label}>{t('transactions.filters.uncategorized')}</span>
      </label>
      {content}
      <label className={`${styles.option} ${styles.descendants}`}>
        <input
          checked={includeDescendants}
          onChange={(event) => onIncludeDescendantsChange(event.target.checked)}
          type="checkbox"
        />
        <span className={styles.label}>{t('transactions.filters.includeDescendants')}</span>
      </label>
    </fieldset>
  );
}
