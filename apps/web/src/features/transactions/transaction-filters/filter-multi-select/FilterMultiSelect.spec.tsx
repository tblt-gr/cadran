import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import i18n from '@/i18n';
import { FilterMultiSelect } from './FilterMultiSelect';

const options = ['A', 'B', 'C'] as const;
type Option = (typeof options)[number];

function renderSelect(value: Option[], onChange = vi.fn()) {
  render(
    <FilterMultiSelect<Option>
      emptyLabel="Toutes"
      label="Choix"
      labelFor={(option) => `Option ${option}`}
      onChange={onChange}
      options={options}
      value={value}
    />,
  );
  return onChange;
}

describe('FilterMultiSelect', () => {
  afterEach(cleanup);

  it('summarises the selection: empty label, the single option, then a count', () => {
    renderSelect([]);
    expect(screen.getByRole('button', { name: /Choix/ }).textContent).toBe('Toutes');
    cleanup();

    renderSelect(['B']);
    expect(screen.getByRole('button', { name: /Choix/ }).textContent).toBe('Option B');
    cleanup();

    renderSelect(['A', 'B']);
    expect(screen.getByRole('button', { name: /Choix/ }).textContent).toBe(
      i18n.t('transactions.filters.selectedCount', { count: 2 }),
    );
  });

  it('adds an unselected option and removes a selected one, leaving the others untouched', () => {
    const onChange = renderSelect(['B']);

    fireEvent.click(screen.getByRole('button', { name: /Choix/ }));
    fireEvent.click(screen.getByRole('option', { name: 'Option A' }));
    expect(onChange).toHaveBeenLastCalledWith(['B', 'A']);

    fireEvent.click(screen.getByRole('option', { name: 'Option B' }));
    expect(onChange).toHaveBeenLastCalledWith([]);
  });
});
