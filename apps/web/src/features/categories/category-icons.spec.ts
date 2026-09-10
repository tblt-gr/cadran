import { describe, expect, it } from 'vitest';
import fr from '@/locales/fr/translation.json';
import { CATEGORY_ICONS, categoryIcon } from './category-icons';

const names: Record<string, string> = fr.categories.identity.icons;

describe('the category icon catalogue', () => {
  it('is a set of unique keys', () => {
    const keys = CATEGORY_ICONS.map((icon) => icon.key);

    expect(new Set(keys).size).toBe(keys.length);
  });

  it('draws every key from a local path and names it in French', () => {
    for (const icon of CATEGORY_ICONS) {
      expect(icon.path, icon.key).toMatch(/^[Mm][\d\s.]/);
      expect(names[icon.key], icon.key).toBeTruthy();
    }
  });

  it('names nothing it cannot draw', () => {
    for (const key of Object.keys(names)) {
      expect(categoryIcon(key), key).toBeTruthy();
    }
  });

  // The key reaches the DOM as a path lookup, so a value outside the catalogue
  // must not resolve at all rather than resolve to something drawable.
  it('rejects anything outside the catalogue', () => {
    for (const value of [
      null,
      undefined,
      '',
      'legacy-icon',
      'toString',
      'constructor',
      '__proto__',
      '<svg onload=alert(1)>',
    ]) {
      expect(categoryIcon(value), String(value)).toBeUndefined();
    }
  });
});
