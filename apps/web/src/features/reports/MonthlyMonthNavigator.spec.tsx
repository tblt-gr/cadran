import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthlyMonthNavigator } from './MonthlyMonthNavigator';

function renderNavigator(props: Partial<React.ComponentProps<typeof MonthlyMonthNavigator>> = {}) {
  const onSelect = vi.fn();
  render(
    <MonthlyMonthNavigator
      currentMonth="2026-09"
      firstDataMonth="2024-03"
      month="2026-09"
      onSelect={onSelect}
      {...props}
    />,
  );
  return onSelect;
}

describe('MonthlyMonthNavigator', () => {
  afterEach(cleanup);

  it('lists every month from the current month back to the first data month, newest first', () => {
    renderNavigator();
    const buttons = within(screen.getByRole('navigation', { name: 'Mois' })).getAllByRole('button');
    expect(buttons).toHaveLength(31);
    expect(buttons[0]?.textContent).toBe('Septembre 2026');
    expect(buttons[buttons.length - 1]?.textContent).toBe('Mars 2024');
  });

  it('offers only the current month for an empty workspace', () => {
    renderNavigator({ firstDataMonth: null });
    expect(
      within(screen.getByRole('navigation', { name: 'Mois' })).getAllByRole('button'),
    ).toHaveLength(1);
  });

  it('marks the selected month as the current page and the current month distinctly', () => {
    renderNavigator({ month: '2026-08' });
    const selected = screen.getByRole('button', { name: 'Août 2026' });
    expect(selected.getAttribute('aria-current')).toBe('page');
    const current = screen.getByRole('button', { name: 'Septembre 2026' });
    expect(current.getAttribute('aria-current')).toBeNull();
    expect(current.getAttribute('data-current')).toBe('true');
  });

  it('selects another month on click', () => {
    const onSelect = renderNavigator();
    fireEvent.click(screen.getByRole('button', { name: 'Juillet 2026' }));
    expect(onSelect).toHaveBeenCalledWith('2026-07');
  });

  it('disables the list while the bounds load', () => {
    const onSelect = renderNavigator({ firstDataMonth: null, status: 'loading' });
    expect(screen.queryAllByRole('button')).toHaveLength(0);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('offers a retry when the bounds failed', () => {
    const onRetry = vi.fn();
    renderNavigator({ onRetry, status: 'error' });
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(onRetry).toHaveBeenCalledOnce();
  });
});
