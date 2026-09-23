import { categoryIcon } from '@/features/categories/category-icons';
import styles from './CategorySwatch.module.css';

interface CategorySwatchProps {
  icon?: string | null;
}

/**
 * The glyph of a category without its label, for the category combobox, whose
 * selected control already carries the fill and ink. Purely decorative: it
 * repeats what the text says and is hidden from assistive technologies.
 */
export function CategorySwatch({ icon }: CategorySwatchProps) {
  const artwork = categoryIcon(icon);

  return (
    <span aria-hidden="true" className={styles.swatch} data-icon={artwork?.key ?? 'none'}>
      {artwork ? (
        <svg className={styles.glyph} focusable="false" viewBox="0 0 24 24">
          <path d={artwork.path} />
        </svg>
      ) : null}
    </span>
  );
}
