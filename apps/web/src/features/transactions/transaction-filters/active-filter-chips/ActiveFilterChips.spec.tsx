import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ActiveFilterChips } from './ActiveFilterChips';

describe('ActiveFilterChips', () => {
  afterEach(() => {
    cleanup();
  });

  it('renders nothing when no filter is active', () => {
    const { container } = render(<ActiveFilterChips chips={[]} onReset={vi.fn()} />);

    expect(container.innerHTML).toBe('');
  });

  it('clears one chip on its own click without resetting the others', () => {
    const onClearA = vi.fn();
    const onClearB = vi.fn();
    render(
      <ActiveFilterChips
        chips={[
          { key: 'a', label: 'Filtre A', onClear: onClearA, removeLabel: 'Retirer le filtre A' },
          { key: 'b', label: 'Filtre B', onClear: onClearB, removeLabel: 'Retirer le filtre B' },
        ]}
        onReset={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Retirer le filtre A' }));

    expect(onClearA).toHaveBeenCalledTimes(1);
    expect(onClearB).not.toHaveBeenCalled();
  });

  it('clears every filter through the text button', () => {
    const onReset = vi.fn();
    render(
      <ActiveFilterChips
        chips={[{ key: 'a', label: 'Filtre A', onClear: vi.fn(), removeLabel: 'Retirer A' }]}
        onReset={onReset}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Tout effacer' }));

    expect(onReset).toHaveBeenCalledTimes(1);
  });
});
