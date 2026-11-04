import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { DirectionFilter } from './DirectionFilter';

describe('DirectionFilter', () => {
  afterEach(cleanup);

  it('marks the current direction as pressed', () => {
    render(<DirectionFilter onChange={vi.fn()} value="OUTFLOW" />);

    expect(screen.getByRole('button', { name: 'Sorties' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    expect(screen.getByRole('button', { name: 'Tous' }).getAttribute('aria-pressed')).toBe('false');
  });

  it('reports the chosen direction, empty for both', () => {
    const onChange = vi.fn();
    render(<DirectionFilter onChange={onChange} value="" />);

    fireEvent.click(screen.getByRole('button', { name: 'Entrées' }));
    expect(onChange).toHaveBeenLastCalledWith('INFLOW');

    cleanup();
    render(<DirectionFilter onChange={onChange} value="INFLOW" />);
    fireEvent.click(screen.getByRole('button', { name: 'Tous' }));
    expect(onChange).toHaveBeenLastCalledWith('');
  });
});
