import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InstrumentForm } from './InstrumentForm';
vi.mock('@/features/auth/useWorkspaceBaseCurrency', () => ({
  useWorkspaceBaseCurrency: () => 'EUR',
}));
vi.mock('@/hooks/use-reference-assets', () => ({
  useReferenceAssets: () => ({
    data: {
      items: [
        { code: 'EUR', kind: 'FIAT', displayPrecision: 2 },
        { code: 'USD', kind: 'FIAT', displayPrecision: 2 },
      ],
    },
    isPending: false,
    isError: false,
  }),
}));
afterEach(cleanup);
describe('InstrumentForm euro fund', () => {
  it('folds technical options away from the essential identifiers', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <InstrumentForm pending={false} onSubmit={vi.fn()} />
      </QueryClientProvider>,
    );
    expect(screen.getByLabelText('Ticker').closest('details')).toBeNull();
    expect(document.querySelector('a[href^="/investments/instruments/"]')).toBeNull();
    expect(screen.getByLabelText('Place de cotation (MIC)').closest('details')?.open).toBe(false);
    fireEvent.click(screen.getByText('Champs avancés'));
    expect(screen.getByLabelText('Précision des prix').closest('details')?.open).toBe(true);
  });
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

it('prefills and locks the account currency and the required type', () => {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <InstrumentForm
        initial={{ name: 'Fonds euros', currencyCode: 'EUR', instrumentType: 'EURO_FUND' }}
        lock={{ currencyCode: true, instrumentType: true }}
        pending={false}
        onSubmit={vi.fn()}
      />
    </QueryClientProvider>,
  );
  expect((screen.getByLabelText('Nom') as HTMLInputElement).value).toBe('Fonds euros');
  expect((screen.getByLabelText('Devise') as HTMLSelectElement).disabled).toBe(true);
  expect((screen.getByLabelText('Type') as HTMLSelectElement).disabled).toBe(true);
  expect(screen.getByText('Devise du compte')).toBeTruthy();
});

it('keeps a locked foreign currency and forbids euro funds outside the workspace base currency', () => {
  const submit = vi.fn();
  render(
    <QueryClientProvider client={new QueryClient()}>
      <InstrumentForm
        initial={{ name: 'Foreign fund', isin: 'IE00BK5BQT80', currencyCode: 'USD' }}
        lock={{ currencyCode: true }}
        pending={false}
        onSubmit={submit}
      />
    </QueryClientProvider>,
  );
  const euro = screen.getByRole('option', { name: 'Fonds en euros' }) as HTMLOptionElement;
  expect(euro.disabled).toBe(true);
  fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'EURO_FUND' } });
  expect((screen.getByLabelText('Devise') as HTMLSelectElement).value).toBe('USD');
  fireEvent.submit(screen.getByLabelText('Nom').closest('form')!);
  expect(submit).not.toHaveBeenCalled();
  expect(
    screen.getByText('Un fonds en euros doit utiliser la devise de référence de l’espace.'),
  ).toBeTruthy();
});
