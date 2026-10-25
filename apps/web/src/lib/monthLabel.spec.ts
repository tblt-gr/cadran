import { describe, expect, it } from 'vitest';
import { formatMonthLabel } from './monthLabel';

describe('formatMonthLabel', () => {
  it('capitalises the long month and year from the text key, in the UTC calendar', () => {
    expect(formatMonthLabel('2026-09', 'fr')).toBe('Septembre 2026');
    expect(formatMonthLabel('2026-01', 'en')).toBe('January 2026');
    expect(formatMonthLabel('2024-12', 'fr')).toBe('Décembre 2024');
  });
});
