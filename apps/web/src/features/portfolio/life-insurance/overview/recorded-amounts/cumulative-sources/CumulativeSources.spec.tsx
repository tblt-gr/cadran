import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { summaryFixture } from '@/features/portfolio/life-insurance/overview/summaryFixture';
import { CumulativeSources } from './CumulativeSources';

afterEach(cleanup);

it('starts closed and exposes dated operation and valuation links and truncated sources when opened', async () => {
  const summary = summaryFixture();
  summary.cumulative.sources.push({ kind: 'VALUATION', id: 'valuation-1', date: summary.asOf });
  render(<CumulativeSources summary={summary} accountId="account-1" />);
  const toggle = screen.getByText('Sources des montants cumulés').closest('summary')!;
  const details = toggle.closest('details')!;
  expect(details.open).toBe(false);
  toggle.focus();
  expect(document.activeElement).toBe(toggle);
  fireEvent.click(toggle);
  await waitFor(() => expect(details.open).toBe(true));
  expect(screen.getByRole('link', { name: 'Opération du 01/01/2025' }).getAttribute('href')).toBe(
    '/portfolios/account-1?asOf=2026-03-10&tab=operations&operation=premium-1',
  );
  expect(
    screen.getByRole('link', { name: 'Valorisation du 10/03/2026' }).getAttribute('href'),
  ).toBe('/portfolios/account-1?asOf=2026-03-10&tab=valuations&valuation=valuation-1');
  expect(screen.getByText('23 autres sources')).toBeTruthy();
  fireEvent.click(toggle);
  await waitFor(() => expect(details.open).toBe(false));
});

it('shows an explicit empty state after expanding', async () => {
  const summary = summaryFixture();
  summary.cumulative.sources = [];
  summary.cumulative.sourceCount = 0;
  render(<CumulativeSources summary={summary} accountId="account-1" />);
  const toggle = screen.getByText('Sources des montants cumulés').closest('summary')!;
  fireEvent.click(toggle);
  await waitFor(() => expect(toggle.closest('details')?.open).toBe(true));
  expect(screen.getByText('Aucune source à cette date.')).toBeTruthy();
  expect(screen.queryByRole('link')).toBeNull();
  expect(screen.queryByText(/autres sources/)).toBeNull();
});

it('keeps the static summary during loading without an empty-data message', () => {
  render(<CumulativeSources summary={null} accountId="account-1" />);
  const toggle = screen.getByText('Sources des montants cumulés').closest('summary')!;
  expect(toggle.closest('details')?.open).toBe(false);
  expect(screen.queryByText('Aucune source à cette date.')).toBeNull();
});
