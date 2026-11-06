import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { PortfolioTakeoverForm } from './PortfolioTakeoverForm';

// The real cap is 200 rows (its value is pinned in takeoverRequest.spec); a small one keeps jsdom fast.
vi.mock('./takeoverRequest', async (original) => ({
  ...(await original<typeof import('./takeoverRequest')>()),
  MAX_TAKEOVER_LINES: 3,
}));
vi.mock('@/features/portfolio/instrument-picker/InstrumentPicker', () => ({
  InstrumentPicker: () => <input aria-label="Instrument" />,
}));
afterEach(cleanup);

it('stops adding rows at the cap', () => {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <PortfolioTakeoverForm
        account={{ id: 'acc-1', assetCode: 'EUR' }}
        defaultDate="2026-10-31"
        onEdit={vi.fn()}
        onSubmit={vi.fn()}
        pending={false}
      />
    </QueryClientProvider>,
  );
  const add = screen.getByRole('button', { name: 'Ajouter une ligne' }) as HTMLButtonElement;
  fireEvent.click(add);
  expect(add.disabled).toBe(false);
  fireEvent.click(add);
  expect(screen.getAllByLabelText('Quantité')).toHaveLength(3);
  expect(add.disabled).toBe(true);
});
