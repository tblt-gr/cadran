import { describe, expect, it } from 'vitest';
import {
  canonicalCategoryColor,
  CATEGORY_INK_DARK,
  CATEGORY_INK_LIGHT,
  categoryInk,
} from './category-color';

describe('canonicalCategoryColor', () => {
  it('upper-cases a usable colour', () => {
    expect(canonicalCategoryColor('#2e7d32')).toBe('#2E7D32');
  });

  // This allowlist is the rendering boundary, so everything it rejects has to
  // read as no colour rather than as a colour the caller may still use.
  it('treats anything but a six-digit hex as no colour', () => {
    for (const value of [
      null,
      undefined,
      '',
      '#FFF',
      '#GGGGGG',
      '2E7D32',
      'red',
      '#AABBCC\n',
      '#2E7D32;background-image:url(https://evil)',
      'var(--negative)',
    ]) {
      expect(canonicalCategoryColor(value), String(value)).toBeNull();
    }
  });
});

describe('categoryInk', () => {
  it('puts dark ink on a light fill', () => {
    expect(categoryInk('#FFFFFF')).toBe(CATEGORY_INK_DARK);
    expect(categoryInk('#F1C086')).toBe(CATEGORY_INK_DARK);
  });

  it('puts light ink on a dark fill', () => {
    expect(categoryInk('#000000')).toBe(CATEGORY_INK_LIGHT);
    expect(categoryInk('#2E7D32')).toBe(CATEGORY_INK_LIGHT);
  });

  // A free colour means mid-tones happen, and both candidates are close there.
  // The rule still has to pick the better one rather than a fixed side.
  it('decides mid-tones by ratio, not by a hardcoded side', () => {
    expect(categoryInk('#808080')).toBe(CATEGORY_INK_DARK);
    expect(categoryInk('#595959')).toBe(CATEGORY_INK_LIGHT);
  });

  it('returns nothing when the colour is unusable', () => {
    expect(categoryInk(null)).toBeNull();
    expect(categoryInk('#12345')).toBeNull();
  });
});
