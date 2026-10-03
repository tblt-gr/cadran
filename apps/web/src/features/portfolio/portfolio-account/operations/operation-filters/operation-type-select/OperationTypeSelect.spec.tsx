import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OperationTypeSelect } from './OperationTypeSelect';

afterEach(cleanup);

describe('OperationTypeSelect', () => {
  it('stays closed until the trigger opens the type list', () => {
    render(<OperationTypeSelect onChange={vi.fn()} value={[]} />);

    expect(screen.queryByRole('listbox', { name: 'Types d’opération' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Types d’opération Tous les types' }));

    expect(screen.getByRole('option', { name: 'Achat' })).toBeTruthy();
    expect(screen.getByRole('option', { name: 'Scission' })).toBeTruthy();
  });

  it('adds and removes a type without closing the list', () => {
    const onChange = vi.fn();
    const { rerender } = render(<OperationTypeSelect onChange={onChange} value={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Types d’opération Tous les types' }));
    fireEvent.click(screen.getByRole('option', { name: 'Achat' }));

    expect(onChange).toHaveBeenCalledWith(['BUY']);
    expect(screen.getByRole('listbox', { name: 'Types d’opération' })).toBeTruthy();

    rerender(<OperationTypeSelect onChange={onChange} value={['BUY']} />);
    fireEvent.click(screen.getByRole('option', { name: 'Achat' }));
    expect(onChange).toHaveBeenLastCalledWith([]);
  });

  it('names the single selected type and counts several', () => {
    const { rerender } = render(<OperationTypeSelect onChange={vi.fn()} value={['BUY']} />);
    expect(screen.getByRole('button', { name: 'Types d’opération Achat' }).textContent).toBe(
      'Achat',
    );

    rerender(<OperationTypeSelect onChange={vi.fn()} value={['BUY', 'SELL']} />);
    expect(screen.getByRole('button', { name: 'Types d’opération 2 types' }).textContent).toBe(
      '2 types',
    );
  });
});
