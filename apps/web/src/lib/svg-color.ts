/** Only canonical hex data may reach a group SVG fill or stroke. */
export function canonicalSvgColor(value: string | null | undefined): string | null {
  return value?.length === 7 && /^#[0-9a-f]{6}$/i.test(value) ? value.toUpperCase() : null;
}

export function svgColor(value: string | null | undefined): string {
  return canonicalSvgColor(value) ?? 'var(--text-muted)';
}

/** WCAG relative luminance is presentation geometry, never a financial calculation. */
export function svgColorInk(value: string | null | undefined): string {
  const color = canonicalSvgColor(value);
  if (!color) return '#FFFFFF';
  const channels = [1, 3, 5].map((offset) => {
    const channel = Number.parseInt(color.slice(offset, offset + 2), 16) / 255;
    return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  });
  const luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
  return (luminance + 0.05) / 0.05 >= 1.05 / (luminance + 0.05) ? '#000000' : '#FFFFFF';
}
