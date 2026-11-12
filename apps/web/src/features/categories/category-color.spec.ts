import { describe, expect, it } from 'vitest';
import { canonicalCategoryColor } from './category-color';

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
