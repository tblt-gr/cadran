import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { annualReport } from '@/features/reports/annual/annualFixtures';
import { multiYearReport } from '@/features/reports/multi-year/multiYearFixtures';
import { flowEntries, FlowsDataTable } from './MultiYearFlowsChart';

afterEach(cleanup);

it('lists one row per year with the three flow columns', () => {
  render(<FlowsDataTable byYear report={multiYearReport} />);
  const table = screen.getByRole('table');
  expect(within(table).getAllByRole('columnheader')).toHaveLength(4);
  expect(within(table).getAllByRole('row').length).toBe(multiYearReport.years.length + 1);
});

it('lists the months of the detailed range when not grouped by year', () => {
  render(<FlowsDataTable annualReports={[annualReport]} byYear={false} report={multiYearReport} />);
  expect(within(screen.getByRole('table')).getAllByRole('row').length).toBeGreaterThan(
    multiYearReport.years.length + 1,
  );
});

it('has no monthly entries without detailed reports', () => {
  expect(flowEntries(undefined)).toBeUndefined();
});
