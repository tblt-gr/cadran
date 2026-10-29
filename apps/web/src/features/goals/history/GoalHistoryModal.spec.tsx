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

  it('opens the latest version only and folds the older ones behind their month', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    const folds = document.querySelectorAll('details');
    expect(folds[0]!.open).toBe(true);
    expect(folds[1]!.open).toBe(false);
    expect(screen.getAllByText('À partir de janvier 2026')).toHaveLength(2);
  });

  it('flags a retroactive correction and shows its reason and day', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    expect(screen.getByText('Rétroactive')).toBeTruthy();
    expect(screen.getByText('Motif : Montant confirmé')).toBeTruthy();
    expect(screen.getAllByText(/Enregistrée le/)).toHaveLength(2);
  });

  it('lists the recorded change of a version with before and after in words for assistive technology', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    const changes = screen.getByRole('list', { name: 'Modifications de la version 2' });
    expect(within(changes).getByText('Montant cible')).toBeTruthy();
    expect(within(changes).getByText(/12\s000,00\s€/)).toBeTruthy();
    expect(within(changes).getByText(/14\s400,00\s€/)).toBeTruthy();
    expect(screen.queryByRole('list', { name: 'Modifications de la version 1' })).toBeNull();
  });

  it('reveals an older version when its summary is activated', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    const older = screen.getByText('Version 1').closest('details')!;
    fireEvent.click(within(older).getByText('Version 1'));
    expect(screen.getAllByText(/Enregistrée le/).length).toBeGreaterThan(0);
  });

  it('summarizes a custom schedule instead of listing twelve amounts', () => {
    const custom = [
      {
        ...items[0]!,
        changes: [
          {
            field: 'schedule' as const,
            before: Array(12).fill('1000.00') as unknown as string,
            after: ['1.00', ...Array(11).fill('2.00')] as unknown as string,
          },
        ],
      },
    ];
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={custom} />);
    expect(screen.getByText(/Répartition personnalisée/)).toBeTruthy();
  });

  it('formats rolling ratios as percentages rather than currency', () => {
    const rolling = [
      {
        ...items[0]!,
        targetAmount: null,
        ratio: '0.35',
        schedule: null,
        changes: [{ field: 'ratio' as const, before: '0.30', after: '0.35' }],
      },
    ];
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={rolling} />);
    expect(screen.getByText(/35,00 %/)).toBeTruthy();
  });

  it('explains the empty history state', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={[]} />);
    expect(screen.getByText('Aucune version n’est disponible.')).toBeTruthy();
  });
});
