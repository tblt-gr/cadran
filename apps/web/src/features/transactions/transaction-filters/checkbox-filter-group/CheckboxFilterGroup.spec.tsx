import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CheckboxFilterGroup } from './CheckboxFilterGroup';

describe('CheckboxFilterGroup', () => {
  afterEach(() => {
    cleanup();
  });

  it('checks the boxes matching the current value and labels each with the given legend', () => {
    render(
      <CheckboxFilterGroup
        labelFor={(option: 'A' | 'B' | 'C') => `Option ${option}`}
        legend="Choices"
        onChange={vi.fn()}
        options={['A', 'B', 'C']}
        value={['B']}
      />,
    );

    expect(screen.getByText('Choices')).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'Option A' })).toHaveProperty('checked', false);
    expect(screen.getByRole('checkbox', { name: 'Option B' })).toHaveProperty('checked', true);
    expect(screen.getByRole('checkbox', { name: 'Option C' })).toHaveProperty('checked', false);
  });

  it('adds an unchecked option and removes a checked one, leaving the others untouched', () => {
    const onChange = vi.fn();
    render(
      <CheckboxFilterGroup
        labelFor={(option: 'A' | 'B' | 'C') => option}
        legend="Choices"
        onChange={onChange}
        options={['A', 'B', 'C']}
        value={['B']}
      />,
    );

    fireEvent.click(screen.getByRole('checkbox', { name: 'A' }));
    expect(onChange).toHaveBeenLastCalledWith(['B', 'A']);

    fireEvent.click(screen.getByRole('checkbox', { name: 'B' }));
    expect(onChange).toHaveBeenLastCalledWith([]);
  });
});
