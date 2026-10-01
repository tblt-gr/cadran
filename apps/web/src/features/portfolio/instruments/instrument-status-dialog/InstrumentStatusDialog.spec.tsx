import type { Instrument } from '@cadran/api-client';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { InstrumentStatusDialog } from './InstrumentStatusDialog';

const instrument: Instrument = {
  id: '00000000-0000-7000-8000-000000000001',
  name: 'ETF Monde',
  isin: null,
  ticker: 'CW8',
  venueMic: null,
  instrumentType: 'ETF',
  assetClass: null,
  region: null,
  currencyCode: 'EUR',
  quantityPrecision: 6,
  pricePrecision: 4,
  source: 'MANUAL',
  reviewed: true,
  reviewedAt: null,
  status: 'ACTIVE',
  used: false,
  version: 1,
};

describe('InstrumentStatusDialog', () => {
  it('shows the lifecycle error and retries through the confirm action', () => {
    const onConfirm = vi.fn();
    render(
      <InstrumentStatusDialog
        error
        instrument={instrument}
        pending={false}
        onConfirm={onConfirm}
      />,
    );
    expect(screen.getByRole('alert').textContent).toContain('La modification est indisponible');
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(onConfirm).toHaveBeenCalledOnce();
  });
});
