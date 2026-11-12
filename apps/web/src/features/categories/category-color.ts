/** This allowlist is also the rendering boundary: arbitrary CSS never reaches an attribute. */
export function canonicalCategoryColor(value: string | null | undefined): string | null {
  return value?.length === 7 && /^#[0-9a-f]{6}$/i.test(value) ? value.toUpperCase() : null;
}
