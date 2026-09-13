import type { CategorizationRuleConditions } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TextConditionFields } from './TextConditionFields';

const emptyConditions: CategorizationRuleConditions = {
  amount: null,
  direction: null,
  mcc: null,
  text: null,
};

describe('TextConditionFields', () => {
  afterEach(cleanup);

  it('adds repeated sources, updates the group combinator and removes a condition by keyboard', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <TextConditionFields conditions={emptyConditions} onChange={onChange} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une condition texte' }));
    expect(onChange).toHaveBeenLastCalledWith({
      ...emptyConditions,
      text: {
        combinator: 'AND',
        predicates: [{ source: 'RAW_LABEL', operator: 'CONTAINS', value: '', negated: false }],
      },
    });

    const conditions: CategorizationRuleConditions = {
      ...emptyConditions,
      text: {
        combinator: 'AND',
        predicates: [
          { source: 'RAW_LABEL', operator: 'CONTAINS', value: 'CARREFOUR', negated: false },
        ],
      },
    };
    rerender(<TextConditionFields conditions={conditions} onChange={onChange} />);

    fireEvent.change(screen.getByRole('combobox', { name: 'Combiner les conditions texte' }), {
      target: { value: 'OR' },
    });
    expect(onChange).toHaveBeenLastCalledWith({
      ...conditions,
      text: { ...conditions.text!, combinator: 'OR' },
    });

    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une condition texte' }));
    const withRepeatedSource = {
      ...conditions,
      text: {
        ...conditions.text!,
        predicates: [
          ...conditions.text!.predicates,
          {
            source: 'RAW_LABEL' as const,
            operator: 'CONTAINS' as const,
            value: '',
            negated: false,
          },
        ],
      },
    };
    expect(onChange).toHaveBeenLastCalledWith(withRepeatedSource);
    rerender(<TextConditionFields conditions={withRepeatedSource} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: 'Supprimer la condition texte 2' }));
    expect(onChange).toHaveBeenLastCalledWith(conditions);
  });

  it('exposes source, comparison, value and negation controls for every row', () => {
    render(
      <TextConditionFields
        conditions={{
          ...emptyConditions,
          text: {
            combinator: 'AND',
            predicates: [
              { source: 'COUNTERPARTY', operator: 'EQUALS', value: 'Carrefour', negated: true },
            ],
          },
        }}
        onChange={vi.fn()}
      />,
    );

    expect(screen.getByRole('combobox', { name: 'Source de la condition texte 1' })).toBeTruthy();
    expect(
      screen.getByRole('combobox', { name: 'Comparaison de la condition texte 1' }),
    ).toBeTruthy();
    expect(screen.getByRole('textbox', { name: 'Valeur de la condition texte 1' })).toBeTruthy();
    expect(
      (screen.getByRole('checkbox', { name: 'Exclure la condition texte 1' }) as HTMLInputElement)
        .checked,
    ).toBe(true);
  });

  it('caps the editor at the 20 predicates accepted by the API', () => {
    const predicates = Array.from({ length: 20 }, () => ({
      source: 'RAW_LABEL' as const,
      operator: 'CONTAINS' as const,
      value: 'CARREFOUR',
      negated: false,
    }));
    render(
      <TextConditionFields
        conditions={{ ...emptyConditions, text: { combinator: 'AND', predicates } }}
        onChange={vi.fn()}
      />,
    );

    expect(
      (screen.getByRole('button', { name: 'Ajouter une condition texte' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
    expect(screen.getByText('Vous pouvez ajouter jusqu’à 20 conditions texte.')).toBeTruthy();
  });
});
