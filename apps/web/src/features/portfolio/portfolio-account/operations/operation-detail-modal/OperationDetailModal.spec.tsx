import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { OperationDetailModal } from './OperationDetailModal';
import {
  operationFixture,
  switchFixture,
} from '@/features/portfolio/portfolio-account/operations/operations-table/operationFixtures';
const amount = (value: string) => ({ value, assetCode: 'EUR' });
afterEach(cleanup);
describe('insurance detail', () => {
  it('shows the observed interest basis and year and derived fee nature', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OperationDetailModal
          onClose={vi.fn()}
          onOpenOperation={vi.fn()}
          operation={operationFixture({
            type: 'EURO_FUND_INTEREST',
            interestBasis: 'NET_OF_MANAGEMENT_FEES',
            rateYear: 2026,
            feeNature: 'MANAGEMENT',
          })}
        />
      </QueryClientProvider>,
    );
    expect(screen.getByText('Net de frais de gestion')).toBeTruthy();
    expect(screen.getByText('2026')).toBeTruthy();
    expect(screen.getByText('Frais de gestion')).toBeTruthy();
  });

  it('shows sold and bought supports separately, the manual fee and dated informative terms', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OperationDetailModal
          onClose={vi.fn()}
          onOpenOperation={vi.fn()}
          operation={switchFixture()}
        />
      </QueryClientProvider>,
    );
    expect(
      within(screen.getByRole('table', { name: 'Vendus' })).getByText('Fonds euros'),
    ).toBeTruthy();
    expect(
      within(screen.getByRole('table', { name: 'Achetés' })).getByText('Fonds actions'),
    ).toBeTruthy();
    expect(screen.getByText('Frais d’arbitrage')).toBeTruthy();
    expect(screen.getByText('10,00 EUR')).toBeTruthy();
    expect(screen.getByText('Barème applicable')).toBeTruthy();
    expect(screen.getByText('0,5 % (informatif)')).toBeTruthy();
  });
  it('leaves missing switch terms and empty support sides explicit', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OperationDetailModal
          onClose={vi.fn()}
          onOpenOperation={vi.fn()}
          operation={switchFixture({ applicableSwitchFeeRate: null, legs: [] })}
        />
      </QueryClientProvider>,
    );
    expect(screen.getAllByText('Aucun support.')).toHaveLength(2);
    expect(screen.getByText('Barème applicable').parentElement?.textContent).toContain(
      'Non renseigné',
    );
  });

  it('shows supports and observed withholdings from the server in the shared modal', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OperationDetailModal
          onClose={vi.fn()}
          onOpenOperation={vi.fn()}
          operation={operationFixture({
            type: 'PARTIAL_SURRENDER',
            grossAmount: amount('1050.00'),
            withheldIncomeTax: amount('7.50'),
            withheldSocialContributions: amount('8.60'),
            netPaidAmount: amount('1033.90'),
            legs: [
              {
                index: 1,
                direction: 'OUT',
                instrument: {
                  id: 'units',
                  name: 'Fonds actions',
                  isin: null,
                  ticker: null,
                  status: 'ACTIVE',
                },
                quantity: '10',
                unitPrice: '105.00',
                amount: amount('1050.00'),
                roundingResidual: null,
              },
            ],
          })}
        />
      </QueryClientProvider>,
    );
    const modal = within(screen.getByRole('dialog', { name: 'Opération : Rachat partiel' }));
    expect(modal.getByRole('table', { name: 'Supports de l’opération' })).toBeTruthy();
    expect(modal.getByText('Sortie')).toBeTruthy();
    expect(modal.getByText('7,50 EUR')).toBeTruthy();
    expect(modal.getByText('8,60 EUR')).toBeTruthy();
    expect(modal.getByText(/1.033,90 EUR/)).toBeTruthy();
  });
  it('leaves the residual cell empty when the server residual is zero', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OperationDetailModal
          onClose={vi.fn()}
          onOpenOperation={vi.fn()}
          operation={operationFixture({
            type: 'PREMIUM',
            legs: [
              {
                index: 1,
                direction: 'IN',
                instrument: {
                  id: 'fund',
                  name: 'Fonds euros',
                  isin: null,
                  ticker: null,
                  status: 'ACTIVE',
                },
                quantity: '6000.00',
                unitPrice: '1',
                amount: amount('6000.00'),
                roundingResidual: amount('0'),
              },
            ],
          })}
        />
      </QueryClientProvider>,
    );
    const table = within(screen.getByRole('dialog', { name: 'Opération : Versement' })).getByRole(
      'table',
      { name: 'Supports de l’opération' },
    );
    expect(within(table).getByText('Non renseigné')).toBeTruthy();
    expect(within(table).queryByText('0,00 €')).toBeNull();
  });
});
