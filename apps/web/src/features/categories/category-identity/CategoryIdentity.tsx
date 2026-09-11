import type { ReactNode } from 'react';
import { canonicalCategoryColor, categoryInk } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import styles from './CategoryIdentity.module.css';

interface CategoryIdentityProps {
  color?: string | null;
  icon?: string | null;
  /** Also accepts the combobox input, keeping editable text immediately beside the glyph. */
  label: ReactNode;
}

/**
 * One pill per category: the stored colour fills it, the optional glyph and the
 * label sit inside it.
 *
 * The fill is a free colour, so the ink is derived from it rather than chosen —
 * see `categoryInk`. Both pieces of metadata are optional and their absence
 * removes pixels: no placeholder glyph, no reserved gap, and a neutral surface
 * when there is no colour. The label is always rendered, which is what keeps the
 * pill readable for anyone who does not perceive the fill.
 */
export function CategoryIdentity({ color, icon, label }: CategoryIdentityProps) {
  const artwork = categoryIcon(icon);
  const fill = canonicalCategoryColor(color);
  const ink = categoryInk(fill);

  return (
    <span
      className={fill === null ? `${styles.identity} ${styles.neutral}` : styles.identity}
      data-icon={artwork?.key ?? 'none'}
      style={fill === null || ink === null ? undefined : { background: fill, color: ink }}
    >
      {artwork ? (
        <svg aria-hidden="true" className={styles.glyph} focusable="false" viewBox="0 0 24 24">
          <path d={artwork.path} />
        </svg>
      ) : null}
      <span className={styles.label}>{label}</span>
    </span>
  );
}
