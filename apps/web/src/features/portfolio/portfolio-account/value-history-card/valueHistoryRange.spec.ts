import { describe, expect, it } from 'vitest';
import { historyUrlValue, parseHistoryPeriod } from './valueHistoryRange';

describe('valueHistoryRange', () => {
  it('defaults to 24 months for a missing or unknown value', () => {
    expect(parseHistoryPeriod(null)).toBe('LAST_24_MONTHS');
    expect(parseHistoryPeriod('7')).toBe('LAST_24_MONTHS');
    expect(parseHistoryPeriod('LAST_12_MONTHS')).toBe('LAST_24_MONTHS');
  });

  it('keeps the saved ?history= values valid and adds ytd', () => {
    expect(parseHistoryPeriod('12')).toBe('LAST_12_MONTHS');
    expect(parseHistoryPeriod('24')).toBe('LAST_24_MONTHS');
    expect(parseHistoryPeriod('all')).toBe('ALL');
    expect(parseHistoryPeriod('ytd')).toBe('YTD');
  });

  it('round-trips every preset through the URL value', () => {
    for (const raw of ['12', '24', 'all', 'ytd']) {
      expect(historyUrlValue(parseHistoryPeriod(raw))).toBe(raw);
    }
  });
});
