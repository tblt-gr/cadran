import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualFixture } from '@/features/portfolio/performance/performance-tab/annualFixtures';
import { PerformanceMonthCards } from './PerformanceMonthCards';

afterEach(cleanup);

describe('PerformanceMonthCards', () => {
  it('renders one card per month and one for the year with the same figures', () => {
    render(<PerformanceMonthCards annual={annualFixture()} onExplain={() => undefined} />);
    expect(screen.getAllByRole('article')).toHaveLength(13);
    const january = screen.getByRole('article', { name: 'janvier' });
    expect(within(january).getByText('Valeur début').nextElementSibling?.textContent).toMatch(
      /10\s000,00/,
    );
    expect(within(january).getByText('Gain').nextElementSibling?.textContent).toContain('+250,00');
    expect(within(january).getByText('MWR (mois)').nextElementSibling?.textContent).toContain(
      '+0,61',
    );
  });

  it('greys a future month and keeps the reason of a non-calculable one', () => {
    render(<PerformanceMonthCards annual={annualFixture()} onExplain={() => undefined} />);
    const future = screen.getByRole('article', { name: 'avril' });
    expect(future.getAttribute('data-status')).toBe('FUTURE');
    expect(within(future).queryAllByRole('button')).toHaveLength(0);
    const march = screen.getByRole('article', { name: 'mars' });
    expect(within(march).getAllByText('Non calculable').length).toBeGreaterThan(0);
  });

  it('opens the explanation of a figure with the month and the year target', () => {
    const onExplain = vi.fn();
    render(<PerformanceMonthCards annual={annualFixture()} onExplain={onExplain} />);
    fireEvent.click(
      within(screen.getByRole('article', { name: 'février' })).getByRole('button', {
        name: /Gain total/,
      }),
    );
    expect(onExplain).toHaveBeenCalledWith({ kind: 'month', month: '2026-02', figure: 'gain' });
    fireEvent.click(
      within(screen.getByRole('article', { name: 'Année 2026' })).getByRole('button', {
        name: /Gain total/,
      }),
    );
    expect(onExplain).toHaveBeenCalledWith({ kind: 'year', figure: 'gain' });
  });
});
