import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { multiYearReport } from '@/features/reports/multi-year/multiYearFixtures';
import { NetWorthDataTable, netWorthAssetCode } from './MultiYearNetWorthChart';

afterEach(cleanup);

it('lists one row per year when no monthly detail is given', () => {
  render(<NetWorthDataTable report={multiYearReport} />);
  const table = screen.getByRole('table');
  expect(within(table).getAllByRole('columnheader')).toHaveLength(2);
  expect(within(table).getAllByRole('row').length).toBe(multiYearReport.years.length + 1);
});

it('reads the asset code of the net worth column when there is no monthly detail', () => {
  expect(netWorthAssetCode(undefined, multiYearReport)).toBe(
    multiYearReport.columns.find((column) => column.id === 'endNetWorth')?.assetCode ?? null,
  );
});
