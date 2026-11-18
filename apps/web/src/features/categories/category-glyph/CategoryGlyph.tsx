import { ColorDot } from '@/components/ui/color-dot/ColorDot';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import styles from './CategoryGlyph.module.css';

interface CategoryGlyphProps {
  color?: string | null;
  icon?: string | null;
  /** Announced and shown as a tooltip: the glyph alone carries no text. */
  label: string;
  /** The name is written right beside the glyph: hide it from assistive technology. */
  decorative?: boolean;
}

/** A category reduced to its glyph, or to its colour dot when it has none. */
export function CategoryGlyph({ color, decorative = false, icon, label }: CategoryGlyphProps) {
  const artwork = categoryIcon(icon);
  const mark = canonicalCategoryColor(color);

  return (
    <span
      aria-hidden={decorative || undefined}
      aria-label={decorative ? undefined : label}
      className={styles.glyph}
      role={decorative ? undefined : 'img'}
      title={decorative ? undefined : label}
    >
      {artwork ? (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24">
          <path d={artwork.path} stroke={mark ?? undefined} />
        </svg>
      ) : (
        <ColorDot color={mark} />
      )}
    </span>
  );
}
