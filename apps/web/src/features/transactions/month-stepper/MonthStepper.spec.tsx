import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MonthStepper } from './MonthStepper';

describe('MonthStepper', () => {
  afterEach(cleanup);

  it('names the month in a polite live region', () => {
    render(<MonthStepper month="2026-11" onStep={vi.fn()} />);

    const label = screen.getByText('Novembre 2026');
    expect(label.getAttribute('aria-live')).toBe('polite');
  });

  it('steps to the previous and next month', () => {
    const onStep = vi.fn();
    render(<MonthStepper month="2026-11" onStep={onStep} />);

    fireEvent.click(screen.getByRole('button', { name: 'Mois précédent' }));
    expect(onStep).toHaveBeenLastCalledWith(-1);

    fireEvent.click(screen.getByRole('button', { name: 'Mois suivant' }));
    expect(onStep).toHaveBeenLastCalledWith(1);
  });

  it('disables both arrows on demand', () => {
    render(<MonthStepper disabled month="2026-11" onStep={vi.fn()} />);

    expect(
      (screen.getByRole('button', { name: 'Mois précédent' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(
      (screen.getByRole('button', { name: 'Mois suivant' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });
});
