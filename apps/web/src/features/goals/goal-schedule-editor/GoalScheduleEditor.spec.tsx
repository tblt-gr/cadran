import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalScheduleEditor } from './GoalScheduleEditor';

const api = vi.hoisted(() => ({ previewGoalSchedule: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

function Harness({ initial }: { initial: { mode: 'LINEAR' | 'CUSTOM'; parts: string[] } }) {
  const [value, setValue] = useState(initial);
  return (
    <GoalScheduleEditor assetCode="EUR" onChange={setValue} targetAmount="2000.00" value={value} />
  );
}

describe('GoalScheduleEditor', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('hides the twelve monthly inputs in linear mode', () => {
    render(<Harness initial={{ mode: 'LINEAR', parts: Array(12).fill('') }} />);
    expect(screen.queryByLabelText('Montant de janvier')).toBeNull();
  });

  it('reveals twelve keyboard-labelled monthly inputs in custom mode', () => {
    render(<Harness initial={{ mode: 'CUSTOM', parts: Array(12).fill('') }} />);
    expect(screen.getByLabelText('Montant de janvier')).toBeTruthy();
    expect(screen.getByLabelText('Montant de décembre')).toBeTruthy();
  });

  it('shows the exact remaining amount to allocate, computed client-side', () => {
    const parts = Array(12).fill('100.00');
    render(<Harness initial={{ mode: 'CUSTOM', parts }} />);
    // 2000.00 - 12*100.00 = 800.00 left to allocate.
    expect(screen.getByRole('status').textContent?.replace(/\s/g, ' ')).toBe(
      'Reste à répartir : 800,00 €',
    );
  });

  it('shows the exact-split message once the twelve parts sum to the target', () => {
    const parts = Array(11).fill('166.67').concat('166.63');
    render(<Harness initial={{ mode: 'CUSTOM', parts }} />);
    expect(screen.getByRole('status').textContent).toBe('Réparti exactement');
  });

  it('fills the twelve fields from the backend preview instead of dividing client-side', async () => {
    api.previewGoalSchedule.mockResolvedValue({
      data: {
        mode: 'LINEAR',
        parts: Array.from({ length: 12 }, (_, i) => ({
          monthIndex: i + 1,
          amount: i < 11 ? '166.67' : '166.63',
          cumulative: '0',
        })),
      },
      response: new Response(null, { status: 200 }),
    });
    render(<Harness initial={{ mode: 'CUSTOM', parts: Array(12).fill('') }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Répartir linéairement' }));
    await waitFor(() => expect(api.previewGoalSchedule).toHaveBeenCalled());
    expect(api.previewGoalSchedule.mock.calls[0][0].query).toEqual({
      target: '2000.00',
      mode: 'LINEAR',
    });
    await waitFor(() =>
      expect((screen.getByLabelText('Montant de janvier') as HTMLInputElement).value).toBe(
        '166.67',
      ),
    );
    expect((screen.getByLabelText('Montant de décembre') as HTMLInputElement).value).toBe('166.63');
  });
});
