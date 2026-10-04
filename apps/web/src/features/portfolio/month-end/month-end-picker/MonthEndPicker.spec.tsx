import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthEndPicker } from './MonthEndPicker';

afterEach(cleanup);

describe('MonthEndPicker', () => {
  it('lists due months oldest first and opens the chosen one', () => {
    const onPick = vi.fn();
    render(<MonthEndPicker disabled={false} dueMonths={['2026-08', '2026-09']} onPick={onPick} />);
    const options = screen.getAllByRole('option').map((option) => option.textContent);
    expect(options).toEqual(['Août 2026', 'Septembre 2026']);
    fireEvent.click(screen.getByRole('button', { name: 'Valoriser une fin de mois' }));
    expect(onPick).toHaveBeenLastCalledWith('2026-08');
    fireEvent.change(screen.getByRole('combobox', { name: 'Mois à valoriser' }), {
      target: { value: '2026-09' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Valoriser une fin de mois' }));
    expect(onPick).toHaveBeenLastCalledWith('2026-09');
  });

  it('is disabled while loading and absent when nothing is due', () => {
    const { rerender } = render(
      <MonthEndPicker disabled={false} dueMonths={null} onPick={vi.fn()} />,
    );
    expect(
      (screen.getByRole('button', { name: 'Valoriser une fin de mois' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
    rerender(<MonthEndPicker disabled={false} dueMonths={[]} onPick={vi.fn()} />);
    expect(screen.queryByRole('button')).toBeNull();
  });
});
