/** Only canonical hex data may reach a group SVG fill or stroke. */
export function canonicalSvgColor(value: string | null | undefined): string | null {
  return value?.length === 7 && /^#[0-9a-f]{6}$/i.test(value) ? value.toUpperCase() : null;
}

export function svgColor(value: string | null | undefined): string {
  return canonicalSvgColor(value) ?? 'var(--text-muted)';
}
