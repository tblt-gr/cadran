import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SegmentedControl } from './SegmentedControl';

const options = [
  { value: 'a', label: 'Premier' },
  { value: 'b', label: 'Second' },
] as const;

describe('SegmentedControl', () => {
  afterEach(cleanup);

  it('names its group and marks the selected option as pressed', () => {
    render(<SegmentedControl label="Vue" onChange={vi.fn()} options={options} value="b" />);

    expect(screen.getByRole('group', { name: 'Vue' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Second' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    expect(screen.getByRole('button', { name: 'Premier' }).getAttribute('aria-pressed')).toBe(
      'false',
    );
  });

  it('reports the chosen value and stays inert when disabled', () => {
    const change = vi.fn();
    const view = render(
      <SegmentedControl label="Vue" onChange={change} options={options} value="a" />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Second' }));
    expect(change).toHaveBeenCalledWith('b');

    view.rerender(
      <SegmentedControl disabled label="Vue" onChange={change} options={options} value="a" />,
    );
    expect(screen.getByRole('button', { name: 'Second' }).matches(':disabled')).toBe(true);
  });
});
