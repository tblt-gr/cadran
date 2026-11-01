import { canonicalSvgColor } from '@/lib/svg-color';

interface ColoredEntry {
  color: string | null;
  groupId: string | null;
}

const MAX_STEP = 0.09;
const MIN_LIGHTNESS = 0.15;
const MAX_LIGHTNESS = 0.85;

function toHsl(hex: string): [number, number, number] {
  const [r, g, b] = [1, 3, 5].map(
    (offset) => Number.parseInt(hex.slice(offset, offset + 2), 16) / 255,
  ) as [number, number, number];
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const lightness = (max + min) / 2;
  if (max === min) return [0, 0, lightness];
  const delta = max - min;
  const saturation = delta / (1 - Math.abs(2 * lightness - 1));
  const hue =
    max === r ? ((g - b) / delta) % 6 : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
  return [(hue * 60 + 360) % 360, saturation, lightness];
}

function toHex(hue: number, saturation: number, lightness: number): string {
  const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation;
  const x = chroma * (1 - Math.abs(((hue / 60) % 2) - 1));
  const m = lightness - chroma / 2;
  const [r, g, b] =
    hue < 60
      ? [chroma, x, 0]
      : hue < 120
        ? [x, chroma, 0]
        : hue < 180
          ? [0, chroma, x]
          : hue < 240
            ? [0, x, chroma]
            : hue < 300
              ? [x, 0, chroma]
              : [chroma, 0, x];
  return `#${[r, g, b]
    .map((channel) =>
      Math.round((channel + m) * 255)
        .toString(16)
        .padStart(2, '0'),
    )
    .join('')
    .toUpperCase()}`;
}

/**
 * Accounts of one group share the group colour; stepping their lightness around it, in response
 * order, keeps neighbouring slices of the donut apart. Presentation only: the amounts and shares
 * stay server strings. An entry without a canonical colour stays null (neutral fallback).
 */
export function shadeWithinGroups(entries: ColoredEntry[]): (string | null)[] {
  const totals = new Map<string | null, number>();
  for (const entry of entries) totals.set(entry.groupId, (totals.get(entry.groupId) ?? 0) + 1);
  const seen = new Map<string | null, number>();

  return entries.map((entry) => {
    const base = canonicalSvgColor(entry.color);
    const count = totals.get(entry.groupId)!;
    const position = seen.get(entry.groupId) ?? 0;
    seen.set(entry.groupId, position + 1);
    if (base === null || count < 2) return base;

    const step = Math.min(MAX_STEP, 0.5 / (count - 1));
    const [hue, saturation, lightness] = toHsl(base);
    const shifted = lightness + (position - (count - 1) / 2) * step;

    return toHex(hue, saturation, Math.min(MAX_LIGHTNESS, Math.max(MIN_LIGHTNESS, shifted)));
  });
}
