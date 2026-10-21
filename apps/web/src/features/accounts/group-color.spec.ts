import { describe, expect, it } from 'vitest';
import { groupColor, groupColorInk } from './group-color';

describe('validated group colors', () => {
  it('canonicalizes hex and rejects CSS, URLs and malformed input', () => {
    expect(groupColor('#aBcD12')).toBe('#ABCD12');
    for (const value of ['url(https://example.com)', 'red', '#fff', '#FFFFFF;fill:red', null]) {
      expect(groupColor(value)).toBe('var(--text-muted)');
    }
  });
  it('keeps labels readable on black and white groups', () => {
    expect(groupColorInk('#000000')).toBe('#FFFFFF');
    expect(groupColorInk('#FFFFFF')).toBe('#000000');
  });
});
