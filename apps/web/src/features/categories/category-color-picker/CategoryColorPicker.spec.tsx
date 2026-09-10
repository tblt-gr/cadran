import { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { CategoryColorPicker } from './CategoryColorPicker';

function Picker({ initial = '' }: { initial?: string }) {
  const [value, onChange] = useState(initial);
  return <CategoryColorPicker onChange={onChange} value={value} />;
}

describe('CategoryColorPicker', () => {
  afterEach(cleanup);

  // The product has no opinion on which colour belongs to which category, so
  // nothing is preselected and no swatch is proposed.
  it('offers no colour at all and no palette until the owner asks for one', () => {
    render(<Picker />);

    expect(screen.getByText('Sans couleur')).toBeTruthy();
    expect(screen.queryAllByRole('radio')).toHaveLength(0);
    expect(screen.queryByLabelText('Code couleur')).toBeNull();
    expect(screen.queryByLabelText('Couleur personnalisée')).toBeNull();
  });

  it('reveals the native control and the exact value once a colour is wanted', () => {
    render(<Picker />);
    const toggle = screen.getByRole('checkbox', {
      name: 'Donner une couleur à cette catégorie',
    });

    expect(toggle.tabIndex).toBe(0);
    fireEvent.click(toggle);

    expect((screen.getByLabelText('Code couleur') as HTMLInputElement).value).toBe('#808080');
    expect((screen.getByLabelText('Couleur personnalisée') as HTMLInputElement).value).toBe(
      '#808080',
    );
  });

  it('synchronizes the editable hex with the native picker in both directions', () => {
    render(<Picker initial="#808080" />);

    fireEvent.change(screen.getByLabelText('Code couleur'), { target: { value: '#aabbcc' } });
    expect((screen.getByLabelText('Couleur personnalisée') as HTMLInputElement).value).toBe(
      '#aabbcc',
    );

    fireEvent.change(screen.getByLabelText('Couleur personnalisée'), {
      target: { value: '#123456' },
    });
    expect((screen.getByLabelText('Code couleur') as HTMLInputElement).value).toBe('#123456');
  });

  it('clears back to no colour in one action', () => {
    render(<Picker initial="#AABBCC" />);

    fireEvent.click(screen.getByRole('checkbox', { name: 'Donner une couleur à cette catégorie' }));

    expect(screen.getByText('Sans couleur')).toBeTruthy();
    expect(screen.queryByLabelText('Code couleur')).toBeNull();
  });

  it('describes invalid hex without propagating it to the native colour input', () => {
    render(<Picker initial="#808080" />);

    fireEvent.change(screen.getByLabelText('Code couleur'), { target: { value: '#zzzzzz' } });
    const input = screen.getByLabelText('Code couleur');

    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(
      document.getElementById(input.getAttribute('aria-describedby') ?? '')?.textContent,
    ).toContain('#RRGGBB');
    // A half-typed value must not drag the swatch to black mid-edit.
    expect((screen.getByLabelText('Couleur personnalisée') as HTMLInputElement).value).toBe(
      '#808080',
    );
  });
});
