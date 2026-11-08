import i18n from 'i18next';
import { describe, expect, it } from 'vitest';
import '@/i18n';
import { goalRecurrence, longMonthYear, shortMonth } from './goalRecurrence';

const t = i18n.t.bind(i18n);
const recurrence = (
  periodicity: 'MONTHLY' | 'YEARLY',
  startPeriod: string,
  endPeriod: string | null,
) => goalRecurrence({ periodicity, startPeriod, endPeriod }, t, 'fr');

describe('goalRecurrence', () => {
  it.each([
    ['MONTHLY', '2026-11', null, 'Chaque mois depuis nov. 2026'],
    ['MONTHLY', '2026-11', '2026-11', 'Novembre 2026 uniquement'],
    ['MONTHLY', '2026-01', '2026-06', 'De janv. à juin 2026'],
    ['MONTHLY', '2025-11', '2026-02', 'De nov. 2025 à févr. 2026'],
    ['YEARLY', '2026', null, 'Chaque année depuis 2026'],
    ['YEARLY', '2026', '2026', 'Année 2026 uniquement'],
    ['YEARLY', '2025', '2027', 'De 2025 à 2027'],
  ] as const)('%s %s to %s reads "%s"', (periodicity, start, end, expected) => {
    expect(recurrence(periodicity, start, end)).toBe(expected);
  });

  it('abbreviates and capitalizes months', () => {
    expect(shortMonth('2026-07', 'fr')).toBe('juil.');
    expect(longMonthYear('2026-11', 'fr')).toBe('Novembre 2026');
  });
});
