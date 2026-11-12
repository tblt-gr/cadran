import type { ReactNode } from 'react';
import { ColorDot } from '@/components/ui/color-dot/ColorDot';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { categoryIcon } from '@/features/categories/category-icons';
import styles from './CategoryIdentity.module.css';

interface CategoryIdentityProps {
  color?: string | null;
  icon?: string | null;
  /** Also accepts the combobox input, keeping editable text immediately beside the glyph. */
  label: ReactNode;
}

/**
 * One tag per category on a neutral surface. The stored colour marks the tag, never fills it: it
 * strokes the glyph, or draws a dot when there is no glyph, so the label keeps the interface's
 * own ink whatever hex the owner chose. Both pieces of metadata are optional and their absence
 * removes pixels: no placeholder glyph and no reserved gap.
 */
export function CategoryIdentity({ color, icon, label }: CategoryIdentityProps) {
  const artwork = categoryIcon(icon);
  const mark = canonicalCategoryColor(color);

  return (
    <span
      className={styles.identity}
      data-color={mark ?? undefined}
      data-icon={artwork?.key ?? 'none'}
    >
      {artwork ? (
        <svg aria-hidden="true" className={styles.glyph} focusable="false" viewBox="0 0 24 24">
          <path d={artwork.path} stroke={mark ?? undefined} />
        </svg>
      ) : (
        <ColorDot color={mark} />
      )}
      <span className={styles.label}>{label}</span>
    </span>
  );
}
