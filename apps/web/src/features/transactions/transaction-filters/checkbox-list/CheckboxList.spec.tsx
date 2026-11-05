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

  describe('counts', () => {
    it('shows no count where the list is given none', () => {
      render(<CheckboxList legend="Choix" onChange={vi.fn()} options={options} value={[]} />);

      expect(screen.getByRole('checkbox', { name: 'Option A' })).toBeTruthy();
      expect(document.querySelector('label [aria-hidden="true"]')).toBeNull();
    });

    it('reads the count of every option after its label, an omitted option holding none', () => {
      render(
        <CheckboxList
          counts={{ A: 12, B: 1 }}
          legend="Choix"
          onChange={vi.fn()}
          options={options}
          value={[]}
        />,
      );

      expect(screen.getByRole('checkbox', { name: 'Option A, 12 transactions' })).toBeTruthy();
      expect(screen.getByRole('checkbox', { name: 'Option B, 1 transaction' })).toBeTruthy();
      expect(screen.getByRole('checkbox', { name: 'Option C, 0 transaction' })).toBeTruthy();
      const visible = [...document.querySelectorAll('label [aria-hidden="true"]')].map(
        (count) => count.textContent,
      );
      expect(visible).toEqual(['12', '1', '0', '0']);
    });

    it('mutes the row of an option that keeps nothing yet leaves it usable', () => {
      const onChange = vi.fn();
      render(
        <CheckboxList
          counts={{ A: 12 }}
          legend="Choix"
          onChange={onChange}
          options={options}
          value={[]}
        />,
      );

      const empty = screen.getByRole('checkbox', { name: 'Option B, 0 transaction' });
      expect(empty.closest('label')?.getAttribute('data-empty')).toBe('true');
      expect(
        screen
          .getByRole('checkbox', { name: 'Option A, 12 transactions' })
          .closest('label')
          ?.hasAttribute('data-empty'),
      ).toBe(false);
      expect(empty).toHaveProperty('disabled', false);

      fireEvent.click(empty);
      expect(onChange).toHaveBeenLastCalledWith(['B']);
    });

    it('shows a skeleton count on every option while the first answer is pending', () => {
      const onChange = vi.fn();
      render(
        <CheckboxList
          counts={null}
          legend="Choix"
          onChange={onChange}
          options={options}
          value={[]}
        />,
      );

      expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(options.length);
      const first = screen.getByRole('checkbox', { name: 'Option A' });
      expect(first).toHaveProperty('disabled', false);
      expect(first.closest('label')?.hasAttribute('data-empty')).toBe(false);

      fireEvent.click(first);
      expect(onChange).toHaveBeenLastCalledWith(['A']);
    });

    it('keeps the count beside options that arrive after it, and in rows past the fold', () => {
      render(
        <CheckboxList
          counts={{ C: 4, D: 9 }}
          legend="Choix"
          limit={2}
          onChange={vi.fn()}
          options={options}
          value={[]}
        />,
      );

      expect(screen.queryByRole('checkbox', { name: /Option C/ })).toBeNull();
      fireEvent.click(screen.getByRole('button', { name: 'Afficher plus (2)' }));
      expect(screen.getByRole('checkbox', { name: 'Option C, 4 transactions' })).toBeTruthy();
      expect(screen.getByRole('checkbox', { name: 'Option D, 9 transactions' })).toBeTruthy();
    });

    it('gives the loading option rows a count placeholder only when counts are asked for', () => {
      const { rerender } = render(
        <CheckboxList legend="Choix" onChange={vi.fn()} options={null} value={[]} />,
      );
      expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(3);

      rerender(
        <CheckboxList counts={null} legend="Choix" onChange={vi.fn()} options={null} value={[]} />,
      );
      expect(document.querySelectorAll('[data-skeleton]')).toHaveLength(6);
    });
  });
});
