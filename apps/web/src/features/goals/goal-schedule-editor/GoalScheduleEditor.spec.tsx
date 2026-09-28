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

function Harness({
  initial,
  targetAmount = '2000.00',
}: {
  initial: { mode: 'LINEAR' | 'CUSTOM'; parts: string[] };
  targetAmount?: string;
}) {
  const [value, setValue] = useState(initial);
  return (
    <GoalScheduleEditor
      assetCode="EUR"
      onChange={setValue}
      targetAmount={targetAmount}
      value={value}
    />
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

  it('does not claim an exact split while a custom part is blank or invalid', () => {
    const parts = Array(11).fill('166.67').concat('');
    render(<Harness initial={{ mode: 'CUSTOM', parts }} />);

    expect(screen.getByRole('status').textContent).toBe('Répartition incomplète ou invalide');
    expect(screen.getByLabelText('Montant de décembre').getAttribute('aria-invalid')).toBe('true');
  });

  it('flags a part above the EUR display precision and never calls the schedule exact', () => {
    const parts = Array(11).fill('100.00').concat('100.005');
    render(<Harness initial={{ mode: 'CUSTOM', parts }} targetAmount="1200.005" />);

    expect(screen.getByRole('status').textContent).toBe('Répartition incomplète ou invalide');
    expect(screen.getByLabelText('Montant de décembre').getAttribute('aria-invalid')).toBe('true');
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

  it('clears the pending preview state and announces a rejected preview', async () => {
    api.previewGoalSchedule.mockRejectedValue(new Error('offline'));
    render(<Harness initial={{ mode: 'CUSTOM', parts: Array(12).fill('') }} />);

    fireEvent.click(screen.getByRole('button', { name: 'Répartir linéairement' }));
    expect(screen.getByRole('button', { name: 'Répartition…' })).toBeTruthy();

    expect((await screen.findByRole('alert')).textContent).toBe('La demande a échoué. Réessayez.');
    expect(screen.getByRole('button', { name: 'Répartir linéairement' })).toBeTruthy();
  });

  it('ignores a preview response made stale by a target change', async () => {
    let resolveFirst: (value: unknown) => void;
    const onChange = vi.fn();
    const value = { mode: 'CUSTOM' as const, parts: Array(12).fill('') };
    api.previewGoalSchedule.mockReturnValue(new Promise((resolve) => (resolveFirst = resolve)));
    const view = render(
      <GoalScheduleEditor
        assetCode="EUR"
        onChange={onChange}
        targetAmount="2000.00"
        value={value}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Répartir linéairement' }));
    view.rerender(
      <GoalScheduleEditor
        assetCode="EUR"
        onChange={onChange}
        targetAmount="2001.00"
        value={value}
      />,
    );
    resolveFirst!({
      data: {
        mode: 'LINEAR',
        parts: Array.from({ length: 12 }, (_, monthIndex) => ({
          monthIndex: monthIndex + 1,
          amount: '1.00',
          cumulative: '0',
        })),
      },
      response: new Response(null, { status: 200 }),
    });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Répartir linéairement' })).toBeTruthy(),
    );
    expect(onChange).not.toHaveBeenCalled();
  });

  it('ignores a preview response made stale by a schedule edit', async () => {
    let resolvePreview: (value: unknown) => void;
    api.previewGoalSchedule.mockReturnValue(new Promise((resolve) => (resolvePreview = resolve)));
    render(<Harness initial={{ mode: 'CUSTOM', parts: Array(12).fill('') }} />);

    fireEvent.click(screen.getByRole('button', { name: 'Répartir linéairement' }));
    fireEvent.change(screen.getByLabelText('Montant de janvier'), { target: { value: '99.00' } });
    resolvePreview!({
      data: {
        mode: 'LINEAR',
        parts: Array.from({ length: 12 }, (_, monthIndex) => ({
          monthIndex: monthIndex + 1,
          amount: '166.67',
          cumulative: '0',
        })),
      },
      response: new Response(null, { status: 200 }),
    });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Répartir linéairement' })).toBeTruthy(),
    );
    expect((screen.getByLabelText('Montant de janvier') as HTMLInputElement).value).toBe('99.00');
  });
});
