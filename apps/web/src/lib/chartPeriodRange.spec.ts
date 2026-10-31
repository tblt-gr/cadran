import { describe, expect, it } from 'vitest';
import {
  CHART_PERIOD_ORDER,
  chartPeriodFrom,
  sortChartPeriods,
  subtractCalendarMonths,
} from './chartPeriodRange';
import { workspaceToday } from './workspaceTime';

describe('chartPeriodRange', () => {
  it('keeps one canonical order for any caller order or subset', () => {
    expect(CHART_PERIOD_ORDER).toEqual(['ALL', 'LAST_24_MONTHS', 'LAST_12_MONTHS', 'YTD']);
    expect(sortChartPeriods(['YTD', 'LAST_24_MONTHS', 'ALL', 'LAST_12_MONTHS'])).toEqual(
      CHART_PERIOD_ORDER,
    );
    expect(sortChartPeriods(['YTD', 'LAST_12_MONTHS'])).toEqual(['LAST_12_MONTHS', 'YTD']);
    expect(sortChartPeriods(['YTD', 'ALL', 'YTD'])).toEqual(['ALL', 'YTD']);
  });

  it('subtracts whole calendar months from a day', () => {
    expect(subtractCalendarMonths('2026-10-03', 12)).toBe('2025-10-03');
    expect(subtractCalendarMonths('2026-10-03', 24)).toBe('2024-10-03');
    expect(subtractCalendarMonths('2026-01-31', 1)).toBe('2025-12-31');
  });

  it('clamps to the last day of a shorter month, leap days included', () => {
    expect(subtractCalendarMonths('2028-02-29', 12)).toBe('2027-02-28');
    expect(subtractCalendarMonths('2024-02-29', 12)).toBe('2023-02-28');
    expect(subtractCalendarMonths('2024-02-29', 24)).toBe('2022-02-28');
    expect(subtractCalendarMonths('2026-03-31', 1)).toBe('2026-02-28');
  });

  it('resolves each preset against the reference day', () => {
    expect(chartPeriodFrom('ALL', '2026-10-03')).toBeUndefined();
    expect(chartPeriodFrom('LAST_12_MONTHS', '2026-10-03')).toBe('2025-10-03');
    expect(chartPeriodFrom('LAST_24_MONTHS', '2026-10-03')).toBe('2024-10-03');
    expect(chartPeriodFrom('YTD', '2026-10-03')).toBe('2025-12-31');
  });

  it('handles year boundaries', () => {
    expect(chartPeriodFrom('YTD', '2026-01-01')).toBe('2025-12-31');
    expect(chartPeriodFrom('YTD', '2025-12-31')).toBe('2024-12-31');
    expect(chartPeriodFrom('LAST_12_MONTHS', '2026-01-01')).toBe('2025-01-01');
    expect(chartPeriodFrom('LAST_24_MONTHS', '2026-01-15')).toBe('2024-01-15');
  });

  it('follows the workspace day across midnight', () => {
    const instant = new Date('2026-12-31T23:30:00Z');
    expect(chartPeriodFrom('YTD', workspaceToday(instant, 'UTC'))).toBe('2025-12-31');
    expect(chartPeriodFrom('YTD', workspaceToday(instant, 'Europe/Paris'))).toBe('2026-12-31');
  });

  it('uses an explicit historical reference day rather than today', () => {
    expect(chartPeriodFrom('YTD', '2023-06-30')).toBe('2022-12-31');
    expect(chartPeriodFrom('LAST_12_MONTHS', '2023-06-30')).toBe('2022-06-30');
  });
});
