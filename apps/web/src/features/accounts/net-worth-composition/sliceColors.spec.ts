import { describe, expect, it } from 'vitest';
import { shadeWithinGroups } from './sliceColors';

const entry = (groupId: string | null, color: string | null) => ({ groupId, color });
const channel = (hex: string) => Number.parseInt(hex.slice(1, 3), 16);

describe('shadeWithinGroups', () => {
  it('keeps the colour of a group that has a single account', () => {
    expect(shadeWithinGroups([entry('g', '#7B96F4')])).toEqual(['#7B96F4']);
  });

  it('keeps the base colour in the middle and steps lighter or darker around it', () => {
    const colors = shadeWithinGroups([
      entry('g', '#808080'),
      entry('g', '#808080'),
      entry('g', '#808080'),
    ]);

    expect(colors[1]).toBe('#808080');
    expect(channel(colors[0]!)).toBeLessThan(channel(colors[1]!));
    expect(channel(colors[2]!)).toBeGreaterThan(channel(colors[1]!));
    expect(new Set(colors).size).toBe(3);
  });

  it('is deterministic and shades each group on its own, in response order', () => {
    const input = [
      entry('a', '#808080'),
      entry('b', '#7B96F4'),
      entry('a', '#808080'),
      entry('b', '#7B96F4'),
    ];
    const colors = shadeWithinGroups(input);

    expect(shadeWithinGroups(input)).toEqual(colors);
    expect(colors[0]).not.toBe(colors[2]);
    expect(colors[1]).not.toBe(colors[3]);
    expect(colors[0]).not.toBe(colors[1]);
  });

  it('leaves an account without a group colour to the neutral fallback', () => {
    expect(shadeWithinGroups([entry(null, null), entry(null, null)])).toEqual([null, null]);
  });

  it('stays a canonical hex string even for many accounts and extreme bases', () => {
    const colors = shadeWithinGroups(Array.from({ length: 40 }, () => entry('g', '#FFFFFF')));

    for (const color of colors) expect(color).toMatch(/^#[0-9A-F]{6}$/);
  });
});
