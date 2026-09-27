import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthlyMonthNavigator } from './MonthlyMonthNavigator';

const api = vi.hoisted(() => ({ readMultiYearReport: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

function renderNavigator(month = '2026-09', currentMonth = '2026-09') {
  const onSelect = vi.fn();
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MonthlyMonthNavigator currentMonth={currentMonth} month={month} onSelect={onSelect} />
    </QueryClientProvider>,
  );
  return onSelect;
}

describe('MonthlyMonthNavigator', () => {
  beforeEach(() => {
    api.readMultiYearReport.mockResolvedValue({
      data: { availableFrom: 2024, from: 2024, to: 2026 },
      response: { ok: true, status: 200 },
    });
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('lists every month from the current month back to January of the first data year, newest first', async () => {
    renderNavigator();
    await screen.findByRole('button', { name: 'Janvier 2024' });
    const nav = screen.getByRole('navigation', { name: 'Mois' });
    const buttons = within(nav).getAllByRole('button');
    expect(buttons).toHaveLength(33);
    expect(buttons[0]?.textContent).toBe('Septembre 2026');
    expect(buttons[buttons.length - 1]?.textContent).toBe('Janvier 2024');
  });

  it('marks the selected month as the current page and the current month distinctly', async () => {
    renderNavigator('2026-08');
    const selected = await screen.findByRole('button', { name: 'Août 2026' });
    expect(selected.getAttribute('aria-current')).toBe('page');
    expect(selected.className).toContain('active');
    const current = screen.getByRole('button', { name: 'Septembre 2026' });
    expect(current.getAttribute('aria-current')).toBeNull();
    expect(current.className).toContain('current');
    expect(screen.getByRole('button', { name: 'Juillet 2026' }).className).toBe('');
  });

  it('selects another month on click', async () => {
    const onSelect = renderNavigator();
    fireEvent.click(await screen.findByRole('button', { name: 'Juillet 2026' }));
    expect(onSelect).toHaveBeenCalledWith('2026-07');
  });
});
