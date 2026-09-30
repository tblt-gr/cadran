import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { GoalSchedule, GoalVersionHistoryItem } from '@cadran/api-client';
import '@/i18n';
import { GoalHistoryModal } from './GoalHistoryModal';

function schedule(year: string, amount: string): GoalSchedule {
  const part = (month: number) => ({
    month: `${year}-${String(month).padStart(2, '0')}`,
    amount,
    cumulative: amount,
  });
  return {
    mode: 'LINEAR',
    parts: [
      part(1),
      part(2),
      part(3),
      part(4),
      part(5),
      part(6),
      part(7),
      part(8),
      part(9),
      part(10),
      part(11),
      part(12),
    ],
  };
}

const items: GoalVersionHistoryItem[] = [
  {
    versionNo: 2,
    effectiveFrom: '2026-01',
    retroactive: true,
    reason: 'Montant confirmé',
    createdAt: '2026-09-10T09:00:00Z',
    createdBy: { id: '00000000-0000-7000-8000-000000000001', displayName: 'Owner' },
    year: 2026,
    targetAmount: '14400.00',
    ratio: null,
    schedule: schedule('2026', '1200.00'),
    changes: [{ field: 'targetAmount' as const, before: '12000.00', after: '14400.00' }],
  },
  {
    versionNo: 1,
    effectiveFrom: '2026-01',
    retroactive: false,
    reason: null,
    createdAt: '2026-01-01T09:00:00Z',
    createdBy: { id: '00000000-0000-7000-8000-000000000001', displayName: 'Owner' },
    year: 2026,
    targetAmount: '12000.00',
    ratio: null,
    schedule: schedule('2026', '1000.00'),
    changes: [],
  },
];

describe('GoalHistoryModal', () => {
  afterEach(cleanup);

  it('reveals explanations only when the information button is activated', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    const button = screen.getByRole('button', { name: 'Comprendre les dates et les corrections' });
    expect(button.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(button);
    expect(button.getAttribute('aria-expanded')).toBe('true');
    const help = document.getElementById(button.getAttribute('aria-controls')!);
    expect(help?.hidden).toBe(false);
    fireEvent.click(button);
    expect(help?.hidden).toBe(true);
  });

  it('summarizes twelve equal monthly amounts in recorded changes and comparison', () => {
    const corrected: GoalVersionHistoryItem = {
      ...items[0]!,
      changes: [
        {
          field: 'schedule' as const,
          before: [
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
            '1000.00',
          ],
          after: [
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
            '1200.00',
          ],
        },
      ],
    };
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={[corrected, items[1]!]} />);
    const recorded = screen.getByRole('table', { name: 'Modifications de la version 2' });
    expect(recorded.textContent).toContain('× 12 mois');
    const comparison = screen.getByRole('table', { name: 'Comparaison des calendriers mensuels' });
    expect(within(comparison).getAllByRole('row')).toHaveLength(2);
    expect(within(comparison).getByText('Janvier à décembre')).toBeTruthy();
  });

  it('keeps all months when an exact monthly amount differs', () => {
    const custom = schedule('2026', '1200.00');
    custom.parts[5].amount = '1200.01';
    render(
      <GoalHistoryModal
        assetCode="EUR"
        close={vi.fn()}
        items={[{ ...items[0]!, schedule: custom }, items[1]!]}
      />,
    );
    const comparison = screen.getByRole('table', { name: 'Comparaison des calendriers mensuels' });
    expect(within(comparison).getAllByRole('row')).toHaveLength(13);
    expect(within(comparison).getByText('juin')).toBeTruthy();
  });

  it('shows retroactive metadata and a side-by-side schedule comparison', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    expect(screen.getByText('Correction rétroactive')).toBeTruthy();
    expect(screen.getByText('Montant confirmé')).toBeTruthy();
    expect(
      screen.getByRole('table', { name: 'Comparaison des calendriers mensuels' }),
    ).toBeTruthy();
  });

  it('explains the empty history state', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={[]} />);
    expect(screen.getByText('Aucune version n’est disponible.')).toBeTruthy();
  });

  it('compares the definitions selected in both controls at mobile width', () => {
    const originalWidth = window.innerWidth;
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 360 });
    try {
      render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
      const before = screen.getByLabelText('Version de référence');
      const after = screen.getByLabelText('Version à comparer');
      expect(before).toBeTruthy();
      expect(after).toBeTruthy();

      fireEvent.change(after, { target: { value: '1' } });
      expect(screen.getByText('Ces versions ont la même définition.')).toBeTruthy();

      fireEvent.change(before, { target: { value: '2' } });
      expect(screen.queryByText('Ces versions ont la même définition.')).toBeNull();
    } finally {
      Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalWidth });
    }
  });

  it('formats rolling ratios as percentages rather than currency', () => {
    const rollingItems: GoalVersionHistoryItem[] = [
      {
        ...items[0],
        targetAmount: null,
        ratio: '0.40',
        schedule: null,
        changes: [{ field: 'ratio', before: '0.35', after: '0.40' }],
      },
      {
        ...items[1],
        targetAmount: null,
        ratio: '0.30',
        schedule: null,
      },
    ];

    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={rollingItems} />);
    expect(screen.getAllByText('30,00 %').length).toBeGreaterThan(0);
    expect(screen.getAllByText('40,00 %').length).toBeGreaterThan(0);
    expect(screen.getByText('35,00 %')).toBeTruthy();
  });

  it('lists the changes the server recorded for each version', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    const table = screen.getByRole('table', { name: 'Modifications de la version 2' });
    expect(table.textContent).toContain('Montant cible');
    expect(table.textContent).toContain('12');
    expect(table.textContent).toContain('14');
    expect(screen.queryByRole('table', { name: 'Modifications de la version 1' })).toBeNull();
  });

  it('translates the schedule mode in recorded changes', () => {
    render(
      <GoalHistoryModal
        assetCode="EUR"
        close={vi.fn()}
        items={[
          {
            ...items[0]!,
            changes: [{ field: 'scheduleMode', before: 'LINEAR', after: 'CUSTOM' }],
          },
        ]}
      />,
    );
    const table = screen.getByRole('table', { name: 'Modifications de la version 2' });
    expect(table.textContent).toContain('Linéaire');
    expect(table.textContent).toContain('Personnalisé');
    expect(table.textContent).not.toContain('LINEAR');
  });
});
