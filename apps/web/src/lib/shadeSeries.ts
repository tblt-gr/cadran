import { canonicalSvgColor } from '@/lib/svg-color';

function toHsl(hex: string): [number, number, number] {
  const [r, g, b] = [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16) / 255) as [
    number,
    number,
    number,
  ];
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
  const offset = lightness - chroma / 2;
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
      Math.round((channel + offset) * 255)
        .toString(16)
        .padStart(2, '0'),
    )
    .join('')
    .toUpperCase()}`;
}

/**
 * `count` shades of one colour, from lighter to darker, keeping its hue: the members of a group read
 * as parts of the group's colour. A colour that is not canonical hex is returned as it is.
 */
export function shadeSeries(color: string | null | undefined, count: number): (string | null)[] {
  const base = canonicalSvgColor(color);
  if (base === null || count <= 1)
    return Array.from({ length: count }, () => base ?? color ?? null);
  const [hue, saturation, lightness] = toHsl(base);
  // Centred on the group colour, with a bounded spread so every slice stays readable on the dark field.
  const spread = Math.min(0.36, 0.09 * (count - 1));
  return Array.from({ length: count }, (_, index) => {
    const shifted = lightness + spread / 2 - (spread * index) / (count - 1);
    return toHex(hue, saturation, Math.min(0.8, Math.max(0.28, shifted)));
  });
}
