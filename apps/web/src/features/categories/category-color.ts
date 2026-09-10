/**
 * Category colours are the owner's free choice — there is no palette to pick
 * from — so nothing guarantees a readable label on top of one. The foreground
 * is therefore computed from the fill instead of chosen alongside it: a pill can
 * never ship dark text on a dark colour, whatever hex was stored.
 */

/** Near-black rather than pure black, so a light fill reads as ink and not as a hole. */
export const CATEGORY_INK_DARK = '#0B0B0C';
export const CATEGORY_INK_LIGHT = '#FFFFFF';

export type CategoryInk = typeof CATEGORY_INK_DARK | typeof CATEGORY_INK_LIGHT;

/** This allowlist is also the rendering boundary: arbitrary CSS never reaches an attribute. */
export function canonicalCategoryColor(value: string | null | undefined): string | null {
  return value?.length === 7 && /^#[0-9a-f]{6}$/i.test(value) ? value.toUpperCase() : null;
}

function channelLuminance(channel: number): number {
  const ratio = channel / 255;

  return ratio <= 0.04045 ? ratio / 12.92 : ((ratio + 0.055) / 1.055) ** 2.4;
}

/** WCAG 2.2 relative luminance of a canonical `#RRGGBB` colour. */
function relativeLuminance(color: string): number {
  return (
    0.2126 * channelLuminance(Number.parseInt(color.slice(1, 3), 16)) +
    0.7152 * channelLuminance(Number.parseInt(color.slice(3, 5), 16)) +
    0.0722 * channelLuminance(Number.parseInt(color.slice(5, 7), 16))
  );
}

function contrastRatio(first: number, second: number): number {
  return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
}

/**
 * The ink with the higher contrast ratio against `value`, or `null` when the
 * colour is unusable and the caller must fall back to the neutral pill.
 */
export function categoryInk(value: string | null | undefined): CategoryInk | null {
  const color = canonicalCategoryColor(value);
  if (color === null) return null;

  const fill = relativeLuminance(color);

  return contrastRatio(fill, relativeLuminance(CATEGORY_INK_DARK)) >=
    contrastRatio(fill, relativeLuminance(CATEGORY_INK_LIGHT))
    ? CATEGORY_INK_DARK
    : CATEGORY_INK_LIGHT;
}
