import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalFilters } from './GoalFilters';

function renderFilters(props: Partial<React.ComponentProps<typeof GoalFilters>> = {}) {
  const handlers = { onPeriodicityChange: vi.fn(), onIncludeEndedChange: vi.fn() };
  render(<GoalFilters includeEnded={false} periodicity={null} {...handlers} {...props} />);
  return handlers;
}

function openFilters() {
  fireEvent.click(screen.getByRole('button', { name: 'Filtres des objectifs' }));
}

describe('GoalFilters', () => {
  afterEach(() => cleanup());

  it('shows no filter count while both defaults hold', () => {
    renderFilters();

    expect(screen.queryByText(/filtre(s)? actif/)).toBeNull();
  });

  it('counts the periodicity and the ended goals as changed filters', () => {
    renderFilters({ includeEnded: true, periodicity: 'MONTHLY' });

    expect(screen.getByText('2 filtres actifs')).toBeTruthy();
  });

  it('offers the periodicity with every goal selected by default and reports a change', () => {
    const { onPeriodicityChange } = renderFilters();

    openFilters();
    const select = screen.getByLabelText('Périodicité') as HTMLSelectElement;
    expect(select.value).toBe('');
    expect([...select.options].map((option) => option.textContent)).toEqual([
      'Toutes',
      'Mensuel',
      'Annuel',
    ]);
    fireEvent.change(select, { target: { value: 'YEARLY' } });
    expect(onPeriodicityChange).toHaveBeenCalledWith('YEARLY');
    fireEvent.change(select, { target: { value: '' } });
    expect(onPeriodicityChange).toHaveBeenLastCalledWith(null);
  });

  it('switches between the current goals and all of them', () => {
    const { onIncludeEndedChange } = renderFilters();

    openFilters();
    expect((screen.getByRole('radio', { name: 'En cours' }) as HTMLInputElement).checked).toBe(
      true,
    );
    fireEvent.click(screen.getByRole('radio', { name: 'Tous' }));
    expect(onIncludeEndedChange).toHaveBeenCalledWith(true);
  });
});
