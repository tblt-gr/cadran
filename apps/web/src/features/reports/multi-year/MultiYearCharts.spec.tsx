import type { AnnualReport } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it } from 'vitest';
import '@/i18n';
import { annualReport } from '@/features/reports/annual/annualFixtures';
import { MultiYearCharts } from './MultiYearCharts';
import { multiYearReport } from './multiYearFixtures';

function reportFor(year: number, emptyUntil = 0): AnnualReport {
  const rename = <T extends { month: string }>(entry: T): T => ({
    ...entry,
    month: `${year}${entry.month.slice(4)}`,
  });
  return {
    ...annualReport,
    year,
    rows: annualReport.rows.map((row, index) => ({
      ...rename(row),
      state: index < emptyUntil ? 'NO_DATA' : row.state,
    })) as AnnualReport['rows'],
    charts: {
      ...annualReport.charts,
      flows: { ...annualReport.charts.flows, months: annualReport.charts.flows.months.map(rename) },
      netWorth: {
        ...annualReport.charts.netWorth,
        months: annualReport.charts.netWorth.months.map(rename),
      },
    },
  };
}

function flowTable() {
  const section = screen.getByRole('region', { name: 'Flux annuels' });
  return section.querySelector('details table') as HTMLElement;
}

beforeEach(() => window.localStorage.clear());
afterEach(cleanup);

it('keeps the months of a partial first year in the flows data', () => {
  // 2023 was only entered from October: its months still count.
  render(
    <MultiYearCharts
      annualReports={[reportFor(2023, 9), reportFor(2024)]}
      report={multiYearReport}
    />,
  );
  const rows = within(flowTable()).getAllByRole('row').slice(1);
  expect(rows).toHaveLength(3 + 12);
  expect(rows[0]?.textContent).toMatch(/^octobre/i);
});

it('groups the flows by year from the header button', () => {
  render(
    <MultiYearCharts annualReports={[reportFor(2024), reportFor(2025)]} report={multiYearReport} />,
  );
  const button = screen.getByRole('button', { name: 'Grouper les flux par année' });
  expect(button.getAttribute('aria-pressed')).toBe('false');
  fireEvent.click(button);
  expect(button.getAttribute('aria-pressed')).toBe('true');
  const table = flowTable();
  expect(within(table).getByRole('columnheader', { name: 'Année' })).toBeTruthy();
  expect(within(table).getAllByRole('row').slice(1)).toHaveLength(multiYearReport.years.length);
});
