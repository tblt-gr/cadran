import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { EmergencyFundExplainModal } from './EmergencyFundExplainModal';
import { coverageShortfall } from './fixtures';

afterEach(cleanup);

describe('EmergencyFundExplainModal', () => {
  it('tags a stale valuation and links each account to its page', () => {
    render(<EmergencyFundExplainModal close={() => {}} coverage={coverageShortfall} />);
    const rows = screen.getAllByRole('row');
    const ldds = rows.find((row) => within(row).queryByRole('link', { name: 'LDDS' }))!;
    expect(within(ldds).getByText('Ancienne valorisation')).toBeTruthy();
    expect(within(ldds).getByRole('link', { name: 'LDDS' }).getAttribute('href')).toBe(
      '/accounts/00000000-0000-7000-8000-0000000000a2',
    );
    const livret = rows.find((row) => within(row).queryByRole('link', { name: 'Livret A' }))!;
    expect(within(livret).queryByText('Ancienne valorisation')).toBeNull();
  });

  it('formats each account in its own asset and the months in the essentials asset', () => {
    const coverage = {
      ...coverageShortfall,
      liquidAssets: {
        ...coverageShortfall.liquidAssets,
        accounts: [{ ...coverageShortfall.liquidAssets.accounts[0], assetCode: 'USD' }],
      },
      reference: { ...coverageShortfall.reference, assetCode: 'EUR' },
    };
    render(<EmergencyFundExplainModal close={() => {}} coverage={coverage} />);
    expect(screen.getByText(/3\s000,00\s\$US/)).toBeTruthy();
    expect(screen.getAllByText(/2\s100,00\s€/).length).toBeGreaterThan(0);
  });

  it('rounds the reference average up to the whole unit', () => {
    const coverage = {
      ...coverageShortfall,
      reference: { ...coverageShortfall.reference, average: '1056.88' },
    };
    render(<EmergencyFundExplainModal close={() => {}} coverage={coverage} />);
    expect(screen.getByText(/moyenne 1\s057\s€/)).toBeTruthy();
  });

  it('labels each reference month as used or excluded with its reason and links it to the budget', () => {
    const coverage = {
      ...coverageShortfall,
      reference: {
        ...coverageShortfall.reference,
        excludedMonths: [
          { month: '2025-01', reason: 'NO_DATA' as const },
          { month: '2025-02', reason: 'PROVISIONAL' as const },
        ],
        months: [
          { month: '2025-01', value: null },
          { month: '2025-02', value: '10.00' },
          { month: '2025-07', value: '2100.00' },
        ],
      },
    };
    render(<EmergencyFundExplainModal close={() => {}} coverage={coverage} />);
    expect(screen.getByText('Exclu (aucune donnée)')).toBeTruthy();
    expect(screen.getByText('Exclu (provisoire)')).toBeTruthy();
    expect(screen.getByText('Utilisé')).toBeTruthy();
    expect(screen.getByText(/6 \/ 12 mois utilisés/)).toBeTruthy();
    expect(screen.getByRole('link', { name: /juillet 2025/i }).getAttribute('href')).toBe(
      '/budget/2025/07',
    );
  });
});
