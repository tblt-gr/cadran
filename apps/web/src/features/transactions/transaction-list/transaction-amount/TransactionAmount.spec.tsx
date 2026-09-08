import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { TransactionAmount } from './TransactionAmount';

describe('TransactionAmount', () => {
  afterEach(() => {
    cleanup();
  });

  it('names an outflow in words and keeps an explicit minus', () => {
    render(<TransactionAmount amount={{ value: '-42.90', assetCode: 'EUR' }} />);

    const amount = screen.getByLabelText(/Sortie de/);
    expect(amount.textContent).toMatch(/−|-/);
  });

  it('names an inflow in words and keeps an explicit plus', () => {
    render(<TransactionAmount amount={{ value: '42.90', assetCode: 'EUR' }} />);

    const amount = screen.getByLabelText(/Entrée de/);
    expect(amount.textContent).toMatch(/\+/);
  });
});
