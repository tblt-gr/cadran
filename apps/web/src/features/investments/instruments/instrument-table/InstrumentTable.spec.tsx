import type { Instrument } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { InstrumentTable } from './InstrumentTable';

afterEach(cleanup);

const instrument: Instrument = {
  id: '00000000-0000-7000-8000-000000000001',
  name: 'ETF Monde',
  isin: 'FR0000120271',
  ticker: 'CW8',
  venueMic: 'XPAR',
  instrumentType: 'ETF',
  assetClass: 'EQUITY',
  region: 'WORLD',
  currencyCode: 'EUR',
  quantityPrecision: 6,
  pricePrecision: 4,
  source: 'MANUAL',
  reviewed: true,
  reviewedAt: '2026-01-01T00:00:00+00:00',
  status: 'ACTIVE',
  used: false,
  version: 1,
};

it('reads identity, class and status first and keeps the identifiers under the name', () => {
  render(<InstrumentTable items={[instrument]} onEdit={vi.fn()} onStatus={vi.fn()} />);

  expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual([
    'Nom',
    'Type',
    'Région',
    'Statut',
    '',
  ]);
  const row = within(screen.getByRole('row', { name: /ETF Monde/ }));
  expect(row.getByText('FR0000120271 · CW8')).toBeTruthy();
  expect(row.getByText('Actions')).toBeTruthy();
  expect(row.getByText('EUR')).toBeTruthy();
  expect(row.getByText('Actif')).toBeTruthy();
});

it('says when an instrument has no identifier or class instead of leaving a blank', () => {
  render(
    <InstrumentTable
      items={[{ ...instrument, isin: null, ticker: null, assetClass: null, region: null }]}
      onEdit={vi.fn()}
      onStatus={vi.fn()}
    />,
  );

  expect(screen.getByText('Aucun identifiant')).toBeTruthy();
  expect(screen.getByText('Classe non renseignée')).toBeTruthy();
  expect(screen.getByText('Non renseignée')).toBeTruthy();
});
