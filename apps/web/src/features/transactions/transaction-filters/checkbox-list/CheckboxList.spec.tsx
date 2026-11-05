import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CheckboxList } from './CheckboxList';

const options = ['A', 'B', 'C', 'D'].map((value) => ({ value, label: `Option ${value}` }));

describe('CheckboxList', () => {
  afterEach(cleanup);

  it('adds and removes a value from its checkbox', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <CheckboxList legend="Choix" onChange={onChange} options={options} value={[]} />,
    );

    fireEvent.click(screen.getByRole('checkbox', { name: 'Option B' }));
    expect(onChange).toHaveBeenLastCalledWith(['B']);

    rerender(<CheckboxList legend="Choix" onChange={onChange} options={options} value={['B']} />);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Option B' }));
    expect(onChange).toHaveBeenLastCalledWith([]);
    expect(screen.getByRole('group', { name: 'Choix' })).toBeTruthy();
  });

  it('folds the rows past the limit but keeps a checked one visible', () => {
    render(
      <CheckboxList legend="Choix" limit={2} onChange={vi.fn()} options={options} value={['D']} />,
    );

    expect(screen.queryByRole('checkbox', { name: 'Option C' })).toBeNull();
    expect(screen.getByRole('checkbox', { name: 'Option D' })).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Afficher plus (1)' }));
    expect(screen.getByRole('checkbox', { name: 'Option C' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Afficher moins' })).toBeTruthy();
  });

  it('renders skeleton rows while loading and its empty text once loaded empty', () => {
    const { rerender } = render(
      <CheckboxList legend="Choix" onChange={vi.fn()} options={null} value={[]} />,
    );
    expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(3);

    rerender(
      <CheckboxList emptyLabel="Rien" legend="Choix" onChange={vi.fn()} options={[]} value={[]} />,
    );
    expect(screen.getByText('Rien')).toBeTruthy();
  });
});
