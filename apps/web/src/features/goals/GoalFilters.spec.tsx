import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { GoalFilters } from './GoalFilters';

describe('GoalFilters', () => {
  afterEach(() => cleanup());

  it('bounds the month picker to the current year, capped at the current month', () => {
    render(
      <GoalFilters
        currentMonth="2026-09"
        month="2026-09"
        onMonthChange={() => {}}
        onYearChange={() => {}}
        year={2026}
      />,
    );

    const monthInput = screen.getByLabelText('Mois') as HTMLInputElement;
    expect(monthInput.min).toBe('2026-01');
    expect(monthInput.max).toBe('2026-09');
  });

  it('allows any month of a past year, uncapped by the current month', () => {
    render(
      <GoalFilters
        currentMonth="2026-09"
        month="2024-12"
        onMonthChange={() => {}}
        onYearChange={() => {}}
        year={2024}
      />,
    );

    const monthInput = screen.getByLabelText('Mois') as HTMLInputElement;
    expect(monthInput.min).toBe('2024-01');
    expect(monthInput.max).toBe('2024-12');
  });

  it('disables the month picker for a future year, whose goals have no month to show yet', () => {
    render(
      <GoalFilters
        currentMonth="2026-09"
        month="2027-01"
        onMonthChange={() => {}}
        onYearChange={() => {}}
        year={2027}
      />,
    );

    expect((screen.getByLabelText('Mois') as HTMLInputElement).disabled).toBe(true);
  });
});
