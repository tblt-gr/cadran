import { afterEach, describe, expect, it } from 'vitest';
import { setLanguage } from '@/i18n';
import { normalizeDecimalInput } from './decimalInput';

describe('normalizeDecimalInput', () => {
  afterEach(async () => {
    await setLanguage('fr');
    window.localStorage.clear();
  });

  it('reads a decimal comma in French', () => {
    expect(normalizeDecimalInput(' 1,5 ')).toBe('1.5');
    expect(normalizeDecimalInput('1,234')).toBe('1.234');
    expect(normalizeDecimalInput('12.5')).toBe('12.5');
  });

  it('keeps the comma in English so the canonical check refuses it', async () => {
    await setLanguage('en');
    expect(normalizeDecimalInput('1,234')).toBe('1,234');
    expect(normalizeDecimalInput('1,234.5')).toBe('1,234.5');
    expect(normalizeDecimalInput(' 12.5 ')).toBe('12.5');
  });
});
