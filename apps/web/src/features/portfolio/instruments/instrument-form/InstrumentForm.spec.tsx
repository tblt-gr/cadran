import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InstrumentForm } from './InstrumentForm';
vi.mock('@/features/auth/useWorkspaceBaseCurrency', () => ({
  useWorkspaceBaseCurrency: () => 'EUR',
}));
vi.mock('@/hooks/use-reference-assets', () => ({
  useReferenceAssets: () => ({
    data: { items: [{ code: 'EUR', kind: 'FIAT', displayPrecision: 2 }] },
    isPending: false,
    isError: false,
  }),
}));
afterEach(cleanup);
describe('InstrumentForm euro fund', () => {
  it('hides identifiers, forces precisions and sends no identifiers after changing type', () => {
    const submit = vi.fn();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <InstrumentForm pending={false} onSubmit={submit} />
      </QueryClientProvider>,
    );
    fireEvent.change(screen.getByLabelText('Ticker'), { target: { value: 'OLD' } });
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'EURO_FUND' } });
    expect(screen.queryByLabelText('ISIN')).toBeNull();
    expect(screen.queryByLabelText('Ticker')).toBeNull();
    expect(screen.queryByLabelText('Place de cotation (MIC)')).toBeNull();
    expect((screen.getByLabelText('Précision des quantités') as HTMLInputElement).value).toBe('2');
    expect((screen.getByLabelText('Précision des prix') as HTMLInputElement).value).toBe('0');
    expect((screen.getByLabelText('Précision des prix') as HTMLInputElement).readOnly).toBe(true);
    expect(screen.getByText('Un fonds en euros vaut toujours 1 € par part.')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Nom'), { target: { value: 'Fonds euros' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit).toHaveBeenCalledWith(
      expect.objectContaining({
        instrumentType: 'EURO_FUND',
        isin: null,
        ticker: null,
        venueMic: null,
        currencyCode: 'EUR',
        quantityPrecision: 2,
        pricePrecision: 0,
      }),
    );
  });
});
