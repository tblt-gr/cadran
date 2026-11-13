import { ColorDot } from '@/components/ui/color-dot/ColorDot';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import styles from './CategorySwatch.module.css';

interface CategorySwatchProps {
  color?: string | null;
  icon?: string | null;
}

/**
 * The mark of a category without its label, for the category combobox: the glyph stroked in the
 * category colour, or a dot when there is no glyph. Purely decorative: it repeats what the text
 * says and is hidden from assistive technologies.
 */
export function CategorySwatch({ color, icon }: CategorySwatchProps) {
  const artwork = categoryIcon(icon);
  const mark = canonicalCategoryColor(color);

  return (
    <span
      aria-hidden="true"
      className={styles.swatch}
      data-color={mark ?? undefined}
      data-icon={artwork?.key ?? 'none'}
    >
      {artwork ? (
        <svg className={styles.glyph} focusable="false" viewBox="0 0 24 24">
          <path d={artwork.path} stroke={mark ?? undefined} />
        </svg>
      ) : (
        <ColorDot color={mark} />
      )}
    </span>
  );
}
