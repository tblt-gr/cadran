import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { performanceFixture } from '@/features/portfolio/performance/performanceFixtures';
import { SinceOpeningFigures } from './SinceOpeningFigures';

const api = vi.hoisted(() => ({ getSession: vi.fn(), readPortfolioPerformance: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const ok = <T,>(data: T) =>
  Promise.resolve({ data, response: new Response('{}', { status: 200 }) });

function renderFigures(frozen = false) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SinceOpeningFigures accountId="account-1" frozen={frozen} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  api.readPortfolioPerformance.mockImplementation(() => ok(performanceFixture()));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('SinceOpeningFigures', () => {
  it('reads the INCEPTION scope and opens the explanation of a figure', async () => {
    renderFigures();
    const section = screen.getByRole('region', { name: "Depuis l'ouverture" });
    await waitFor(() =>
      expect(
        within(section)
          .getByRole('button', { name: /Gain total/ })
          .hasAttribute('disabled'),
      ).toBe(false),
    );
    expect(api.readPortfolioPerformance).toHaveBeenCalledWith(
      expect.objectContaining({ query: { scope: 'INCEPTION' } }),
    );
    fireEvent.click(within(section).getByRole('button', { name: /Gain total/ }));
    expect(await screen.findByRole('dialog', { name: 'Expliquer : Gain total' })).toBeTruthy();
  });

  it('keeps the title and sends no request while frozen', () => {
    renderFigures(true);
    expect(screen.getByRole('heading', { name: "Depuis l'ouverture" })).toBeTruthy();
    expect(api.readPortfolioPerformance).not.toHaveBeenCalled();
  });
});
