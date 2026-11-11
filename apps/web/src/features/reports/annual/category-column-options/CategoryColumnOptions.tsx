import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { buildTree, type TreeNode } from '@/lib/tree/buildTree';
import type { CatalogueEntry } from '@/features/reports/annual/useColumnCatalogue';
import styles from './CategoryColumnOptions.module.css';

const SKELETON_ROWS = 4;
const PATH_SEPARATOR = ' › ';

interface CategoryOption {
  id: string;
  parentId: string | null;
  entry: CatalogueEntry;
}

interface CategoryColumnOptionsProps {
  /** `null` while the catalogue loads: the final structure is drawn with blocks for the labels. */
  entries: CatalogueEntry[] | null;
  /** Reached the column limit: unchecked options stay visible but cannot be added. */
  full: boolean;
  onToggle: (columnId: string) => void;
  selected: readonly string[];
}

function bySortOrder(a: CategoryOption, b: CategoryOption): number {
  return (
    (a.entry.category?.sortOrder ?? 0) - (b.entry.category?.sortOrder ?? 0) ||
    a.entry.label.localeCompare(b.entry.label)
  );
}

/**
 * Category columns as the category tree of the Transactions filter: parents before their
 * children, indented, with the effective colour mark. Each checkbox selects its own column only.
 * A category whose parent is missing from the catalogue is a root (see `buildTree`). Tree order
 * applies to these choices only, never to the order of the selected columns.
 */
export function CategoryColumnOptions({
  entries,
  full,
  onToggle,
  selected,
}: CategoryColumnOptionsProps) {
  const { t } = useTranslation();
  const legend = t('reports.annual.picker.categories');

  if (entries === null) {
    return (
      <fieldset className={styles.group}>
        <legend className="sr-only">{legend}</legend>
        <ul className={styles.list}>
          {Array.from({ length: SKELETON_ROWS }, (_, index) => (
            <li key={index}>
              <label className={styles.option}>
                <input disabled type="checkbox" />
                <span aria-hidden="true" className={`${styles.mark} ${styles.neutral}`} />
                <SkeletonBlock className={styles.skeleton} />
              </label>
            </li>
          ))}
        </ul>
      </fieldset>
    );
  }
  if (entries.length === 0) {
    return <p>{t('reports.annual.picker.none')}</p>;
  }

  const options = entries
    .map((entry): CategoryOption => ({
      id: entry.id,
      parentId: entry.category?.parentId ?? null,
      entry,
    }))
    .sort(bySortOrder);
  const labels = new Map(options.map((option) => [option.id, option]));
  /** Ancestry as the accessible name: two "Autre" under different parents stay distinct. */
  function pathOf(option: CategoryOption): string {
    const names = [option.entry.label];
    const seen = new Set([option.id]);
    for (
      let parent = option.parentId === null ? undefined : labels.get(option.parentId);
      parent !== undefined && !seen.has(parent.id);
      parent = parent.parentId === null ? undefined : labels.get(parent.parentId)
    ) {
      seen.add(parent.id);
      names.unshift(parent.entry.label);
    }
    return names.join(PATH_SEPARATOR);
  }

  function renderNodes(nodes: TreeNode<CategoryOption>[]) {
    return nodes.map(({ item, children }) => {
      const columnId = `category:${item.id}`;
      const checked = selected.includes(columnId);
      const color = canonicalCategoryColor(item.entry.category?.effectiveColor);
      return (
        <li key={item.id}>
          <label className={styles.option}>
            <input
              aria-label={pathOf(item)}
              checked={checked}
              disabled={!checked && full}
              onChange={() => onToggle(columnId)}
              type="checkbox"
            />
            <span
              aria-hidden="true"
              className={color === null ? `${styles.mark} ${styles.neutral}` : styles.mark}
              style={color === null ? undefined : { background: color }}
            />
            <span className={styles.label}>{item.entry.label}</span>
          </label>
          {children.length > 0 ? (
            <ul className={`${styles.list} ${styles.children}`}>{renderNodes(children)}</ul>
          ) : null}
        </li>
      );
    });
  }

  return (
    <fieldset className={styles.group}>
      <legend className="sr-only">{legend}</legend>
      <ul className={styles.list}>{renderNodes(buildTree(options))}</ul>
    </fieldset>
  );
}
