import { describe, expect, it } from 'vitest';
import { historyFrom, parseHistoryPeriod } from './valueHistoryRange';

describe('valueHistoryRange', () => {
  it('goes back 12 or 24 calendar months and leaves Tout open', () => {
    expect(historyFrom('2026-10-03', '12')).toBe('2025-10-03');
    expect(historyFrom('2026-10-03', '24')).toBe('2024-10-03');
    expect(historyFrom('2026-10-03', 'all')).toBeUndefined();
  });

  it('keeps the last day of a shorter month', () => {
    expect(historyFrom('2028-02-29', '12')).toBe('2027-02-28');
  });

  it('defaults to 24 months for a missing or unknown value', () => {
    expect(parseHistoryPeriod(null)).toBe('24');
    expect(parseHistoryPeriod('7')).toBe('24');
    expect(parseHistoryPeriod('12')).toBe('12');
    expect(parseHistoryPeriod('all')).toBe('all');
  });
});
