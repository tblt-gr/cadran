import { readFileSync } from 'node:fs';
const css = readFileSync(
  'src/features/portfolio/life-insurance/overview/allocation-card/LifeInsuranceAllocationCard.module.css',
  'utf8',
);
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { LifeInsuranceAllocationCard } from './LifeInsuranceAllocationCard';
afterEach(cleanup);
it('preserves zero values while showing null shares as non calculable', () => {
  const summary = summaryFixture();
  summary.allocation.items = [{ kind: 'CASH', value: '0', share: null }];
  render(<LifeInsuranceAllocationCard summary={summary} />);
  const table = screen.getByRole('table');
  expect(within(table).getByText(/0,00/)).toBeTruthy();
  expect(within(table).getByText('Non calculable')).toBeTruthy();
  expect(screen.queryByRole('img')).toBeNull();
});
it('keeps signed shares in the table without drawing a misleading donut', () => {
  const summary = summaryFixture();
  summary.allocation.items = [{ kind: 'CASH', value: '-1', share: '-0.01' }];
  render(<LifeInsuranceAllocationCard summary={summary} />);
  expect(within(screen.getByRole('table')).getByText('-1,00 %')).toBeTruthy();
  expect(screen.getByText(/Parts non calculables ou signées/)).toBeTruthy();
});
it('does not lose digits in very large decimal values', () => {
  const summary = summaryFixture();
  summary.allocation.items = [{ kind: 'CASH', value: '99999999999999999999999999.12', share: '1' }];
  render(<LifeInsuranceAllocationCard summary={summary} />);
  const amount = within(screen.getByRole('table')).getByRole('cell', { name: /,12/ });
  expect(amount.textContent?.replace(/[^0-9,]/g, '')).toBe('99999999999999999999999999,12');
});

it('preserves the chart wrapper, donut height and known legend/table labels while values load', () => {
  const view = render(<LifeInsuranceAllocationCard summary={null} />);
  const legend = screen.getByRole('list', { name: 'Légende de la répartition' });
  const chartClass = legend.parentElement!.parentElement!.className;
  const plot = screen.getByTestId('allocation-donut-skeleton');
  expect(plot.parentElement!.className).toBe(chartClass);
  expect(css).toMatch(/\.plotSkeleton\s*\{[^}]*height:\s*var\(--chart-plot-height-donut\)/);
  expect(css).toMatch(/@media \(min-width: 768px\)/);
  for (const label of ['Fonds en euros', 'Unités de compte', 'Liquidités']) {
    expect(within(legend).getByText(label)).toBeTruthy();
    expect(within(screen.getByRole('table')).getByRole('rowheader', { name: label })).toBeTruthy();
  }
  expect(legend.textContent).not.toMatch(/\d/);
  expect(
    within(screen.getByRole('table'))
      .getAllByRole('cell')
      .every((cell) => cell.querySelector('[aria-hidden="true"]')),
  ).toBe(true);
  view.rerender(<LifeInsuranceAllocationCard summary={summaryFixture()} />);
  expect(
    screen.getByRole('list', { name: 'Légende de la répartition' }).parentElement!.parentElement!
      .className,
  ).toBe(chartClass);
  expect(screen.queryByTestId('allocation-donut-skeleton')).toBeNull();
});
