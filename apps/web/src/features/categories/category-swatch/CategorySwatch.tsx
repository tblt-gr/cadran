import { canonicalCategoryColor, categoryInk } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import styles from './CategorySwatch.module.css';

interface CategorySwatchProps {
  color?: string | null;
  icon?: string | null;
}

/**
 * The colour and glyph of a category without its label, for a place that
 * already shows the label as text — the category combobox. Purely decorative:
 * it repeats what the text says and is hidden from assistive technologies.
 */
export function CategorySwatch({ color, icon }: CategorySwatchProps) {
  const artwork = categoryIcon(icon);
  const fill = canonicalCategoryColor(color);
  const ink = categoryInk(fill);

  return (
    <span
      aria-hidden="true"
      className={fill === null ? `${styles.swatch} ${styles.neutral}` : styles.swatch}
      data-icon={artwork?.key ?? 'none'}
      style={fill === null || ink === null ? undefined : { background: fill, color: ink }}
    >
      {artwork ? (
        <svg className={styles.glyph} focusable="false" viewBox="0 0 24 24">
          <path d={artwork.path} />
        </svg>
      ) : null}
    </span>
  );
}
