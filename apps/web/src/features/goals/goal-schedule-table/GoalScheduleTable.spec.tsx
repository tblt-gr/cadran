import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import '@/i18n';
import { GoalScheduleTable } from './GoalScheduleTable';

const schedule = {
  mode: 'LINEAR' as const,
  parts: [
    { month: '2026-01', amount: '166.67', cumulative: '166.67' },
    { month: '2026-02', amount: '166.67', cumulative: '333.34' },
    { month: '2026-03', amount: '166.67', cumulative: '500.01' },
    { month: '2026-04', amount: '166.67', cumulative: '666.68' },
    { month: '2026-05', amount: '166.67', cumulative: '833.35' },
    { month: '2026-06', amount: '166.67', cumulative: '1000.02' },
    { month: '2026-07', amount: '166.67', cumulative: '1166.69' },
    { month: '2026-08', amount: '166.67', cumulative: '1333.36' },
    { month: '2026-09', amount: '166.67', cumulative: '1500.03' },
    { month: '2026-10', amount: '166.67', cumulative: '1666.70' },
    { month: '2026-11', amount: '166.67', cumulative: '1833.37' },
    { month: '2026-12', amount: '166.63', cumulative: '2000.00' },
  ] as unknown as [
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
    { month: string; amount: string; cumulative: string },
  ],
};

describe('GoalScheduleTable', () => {
  it('shows a not-calculable notice when the goal carries no schedule', () => {
    render(<GoalScheduleTable assetCode="EUR" schedule={null} />);
    expect(screen.getByText('Aucun calendrier mensuel pour cet objectif.')).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
  });

  it('renders the twelve months with their exact part and running total from the API', () => {
    render(<GoalScheduleTable assetCode="EUR" schedule={schedule} />);
    const rows = screen.getAllByRole('row');
    expect(rows).toHaveLength(13); // header + 12 months
    expect(screen.getByRole('rowheader', { name: 'janvier 2026' })).toBeTruthy();
    expect(screen.getByRole('rowheader', { name: 'décembre 2026' })).toBeTruthy();
    // December's exact residual differs from the other eleven parts.
    expect(screen.getByText('166,63 €')).toBeTruthy();
    expect(screen.getByText('2 000,00 €')).toBeTruthy();
  });
});
