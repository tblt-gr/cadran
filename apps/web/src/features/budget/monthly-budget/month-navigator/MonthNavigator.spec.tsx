import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { MonthNavigator } from './MonthNavigator';

describe('MonthNavigator', () => {
  afterEach(cleanup);

  it('exposes future months as disabled buttons and past months as links', () => {
    render(<MonthNavigator axis={null} currentMonth="2026-03" month="2026-03" />);

    const future = screen.getByRole('button', { name: 'Avril' }) as HTMLButtonElement;
    expect(future.disabled).toBe(true);
    expect(screen.getByRole('link', { name: 'Février' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Mars' }).getAttribute('aria-current')).toBe('page');
  });

  function yearOptions() {
    return within(screen.getByRole('combobox', { name: 'Année' }))
      .getAllByRole('option')
      .map((option) => option.textContent);
  }

  it('lists years from the first year with data up to the current year', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth="2024-11"
        month="2026-03"
      />,
    );

    expect(yearOptions()).toEqual(['2026', '2025', '2024']);
  });

  it('keeps the selected year even when it predates the first data', () => {
    render(
      <MonthNavigator
        axis={null}
        currentMonth="2026-03"
        firstDataMonth="2025-01"
        month="2023-05"
      />,
    );

    expect(yearOptions()).toEqual(['2026', '2025', '2024', '2023']);
  });

  it('offers only the current year when there is no data yet', () => {
    render(
      <MonthNavigator axis={null} currentMonth="2026-03" firstDataMonth={null} month="2026-03" />,
    );

    expect(yearOptions()).toEqual(['2026']);
  });

  it('marks the active month with the primary colour class and no other decoration', () => {
    render(<MonthNavigator axis={null} currentMonth="2026-03" month="2026-03" />);

    const active = screen.getByRole('link', { name: 'Mars' });
    expect(active.getAttribute('aria-current')).toBe('page');
    expect(active.className).toContain('active');
    expect(screen.getByRole('link', { name: 'Février' }).className).toBe('');
  });
});
