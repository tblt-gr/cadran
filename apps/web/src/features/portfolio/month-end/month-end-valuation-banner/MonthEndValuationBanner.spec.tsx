import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { summaryFixture } from '@/features/portfolio/portfolio-account/overviewFixtures';
import { MonthEndValuationBanner } from './MonthEndValuationBanner';

const api = vi.hoisted(() => ({ readPortfolioSummary: vi.fn() }));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  window.history.pushState({}, '', '/');
});

function renderBanner() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MonthEndValuationBanner accountId="acc-1" asOf="2026-09-30" />
    </QueryClientProvider>,
  );
}
const summary = (dueMonths: string[]) =>
  Promise.resolve({
    data: { ...summaryFixture(), dueMonths },
    response: new Response('{}', { status: 200 }),
  });

describe('MonthEndValuationBanner', () => {
  it('lists one line per due month with a deep link to the valuation modal', async () => {
    api.readPortfolioSummary.mockReturnValue(summary(['2026-08', '2026-09']));
    renderBanner();
    expect(await screen.findByText('Valorisation de fin août à saisir')).toBeTruthy();
    expect(screen.getByText('Valorisation de fin septembre à saisir')).toBeTruthy();
    const links = screen.getAllByRole('link', { name: /Saisir la valorisation/ });
    expect(links).toHaveLength(2);
    expect(links[0]!.getAttribute('href')).toBe(
      '/portfolios/acc-1?tab=valuations&monthEnd=2026-08',
    );
    fireEvent.click(links[1]!);
    expect(window.location.search).toBe('?tab=valuations&monthEnd=2026-09');
  });

  it('renders nothing when no month is due', async () => {
    api.readPortfolioSummary.mockReturnValue(summary([]));
    const { container } = renderBanner();
    await vi.waitFor(() => expect(api.readPortfolioSummary).toHaveBeenCalled());
    await vi.waitFor(() => expect(container.querySelector('[aria-busy]')).toBeNull());
    expect(screen.queryByText(/à saisir/)).toBeNull();
  });

  it('shows a loading status then an error with a retry', async () => {
    api.readPortfolioSummary.mockReturnValueOnce(
      Promise.resolve({ error: {}, response: new Response('{}', { status: 500 }) }),
    );
    renderBanner();
    expect(screen.getByRole('status').textContent).toContain('Chargement');
    expect(await screen.findByRole('alert')).toBeTruthy();
    api.readPortfolioSummary.mockReturnValue(summary(['2026-09']));
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByText('Valorisation de fin septembre à saisir')).toBeTruthy();
  });
});
