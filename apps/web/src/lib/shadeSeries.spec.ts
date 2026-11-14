import { describe, expect, it } from 'vitest';
import { shadeSeries } from './shadeSeries';

describe('shadeSeries', () => {
  it('gives each member its own shade of the same hue, lighter first', () => {
    const shades = shadeSeries('#486DF0', 4);
    expect(new Set(shades).size).toBe(4);
    const lightness = (hex: string) =>
      parseInt(hex.slice(1, 3), 16) + parseInt(hex.slice(5, 7), 16);
    // The blue channel dominates in every shade: the hue is kept.
    for (const hex of shades) {
      expect(parseInt(hex!.slice(5, 7), 16)).toBeGreaterThan(parseInt(hex!.slice(1, 3), 16));
    }
    expect(lightness(shades[0]!)).toBeGreaterThan(lightness(shades[3]!));
  });

  it('keeps a single member on the group colour and leaves a non hex colour alone', () => {
    expect(shadeSeries('#486DF0', 1)).toEqual(['#486DF0']);
    expect(shadeSeries('not-a-colour', 2)).toEqual(['not-a-colour', 'not-a-colour']);
    expect(shadeSeries(null, 0)).toEqual([]);
  });
});
