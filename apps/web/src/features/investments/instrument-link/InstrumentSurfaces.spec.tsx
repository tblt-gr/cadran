import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ValuationPositionsTable } from '@/features/portfolio/valuations/valuation-detail-modal/ValuationPositionsTable';
import { positionsValuationFixture } from '@/features/portfolio/valuations/valuationFixtures';
import { OperationLegsTable } from '@/features/portfolio/portfolio-account/operations/operation-detail-modal/OperationLegsTable';
import { switchFixture } from '@/features/portfolio/portfolio-account/operations/operations-table/operationFixtures';
import { EuroFundRatesCard } from '@/features/portfolio/life-insurance/euro-fund-rates-card/EuroFundRatesCard';
import { HoldingsTable } from '@/features/investments/holdings-table/HoldingsTable';
import { HoldingsByAccountTable } from '@/features/investments/holdings-by-account/HoldingsByAccountTable';
import { holdingsFixture } from '@/features/investments/holdingsFixture';
import { AllocationCard } from '@/features/portfolio/portfolio-account/allocation-card/AllocationCard';
import { allocationFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
import { instrumentSheetFixture } from '@/features/investments/instrument-sheet/instrumentSheetFixture';
import { DuplicateNotice } from '@/features/investments/instruments/duplicate-notice/DuplicateNotice';
afterEach(cleanup);
function links() {
  return screen.getAllByRole('link', { name: /^Voir l.instrument/ });
}
describe('read-only instrument names', () => {
  it('links valuation detail names', () => {
    render(<ValuationPositionsTable valuation={positionsValuationFixture()} />);
    expect(links().length).toBeGreaterThan(0);
  });
  it('links outgoing and incoming detail legs', () => {
    render(<OperationLegsTable legs={switchFixture().legs!} />);
    expect(links()).toHaveLength(2);
  });
  it('links holdings by asset', () => {
    render(<HoldingsTable holdings={holdingsFixture()} sort="VALUE_DESC" onSortChange={vi.fn()} />);
    expect(links()).toHaveLength(2);
  });
  it('links holdings by account', () => {
    render(<HoldingsByAccountTable holdings={holdingsFixture()} />);
    fireEvent.click(screen.getAllByRole('button', { expanded: false })[0]!);
    expect(links().length).toBeGreaterThan(0);
  });
  it('links an instrument allocation legend and its detailed table', () => {
    const allocation = allocationFixture();
    allocation.by = 'INSTRUMENT';
    allocation.items = [
      { key: '00000000-0000-7000-8000-000000000001', label: 'Monde', value: '10', share: '1' },
    ];
    allocation.slices = allocation.items;
    render(<AllocationCard allocation={allocation} by="INSTRUMENT" onByChange={vi.fn()} />);
    expect(links()).toHaveLength(2);
  });
  it('links the euro fund missing-rate warning', () => {
    render(
      <EuroFundRatesCard
        items={[
          {
            id: 'rate',
            instrumentId: 'fund',
            year: 2024,
            servedRate: '2',
            bonusRate: null,
            basis: 'UNKNOWN',
            sourceNote: null,
          },
        ]}
        euroFunds={[
          {
            instrument: { ...instrumentSheetFixture().instrument, id: 'fund', name: 'Fonds euros' },
            latestRateYear: null,
            missingLastYear: true,
          },
        ]}
        lastClosedYear={2025}
        onAdd={vi.fn()}
        onRemove={vi.fn()}
      />,
    );
    expect(screen.getAllByRole('link', { name: "Voir l'instrument Fonds euros" })).toHaveLength(2);
  });
  it('keeps duplicate candidate names as text', () => {
    render(
      <DuplicateNotice
        candidates={[{ id: 'x', name: 'Monde', isin: null, ticker: null, status: 'ACTIVE' }]}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.queryByRole('link')).toBeNull();
  });
});
