import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GroupColorField } from './GroupColorField';

type Props = Parameters<typeof GroupColorField>[0];

function renderField(props: Partial<Props> = {}) {
  const onChange = vi.fn();
  render(
    <GroupColorField
      color={null}
      disabled={false}
      mode="AUTO"
      onChange={onChange}
      root
      swatches={{ AUTO: '#486DF0', PARENT: null }}
      {...props}
    />,
  );
  return onChange;
}

function swatchOf(radioName: string): string | undefined {
  const label = screen.getByRole('radio', { name: new RegExp(radioName) }).closest('label');
  return (label?.querySelector('[data-swatch]') as HTMLElement | null)?.style.backgroundColor;
}

describe('GroupColorField', () => {
  afterEach(cleanup);

  it('is a fieldset named Couleur offering default and custom to a root', () => {
    renderField();

    const group = screen.getByRole('group', { name: 'Couleur' });
    expect(
      within(group)
        .getAllByRole('radio')
        .map((r) => r.closest('label')?.textContent),
    ).toEqual(['Couleur par défaut', 'Personnalisée']);
    expect(
      (screen.getByRole('radio', { name: /Couleur par défaut/ }) as HTMLInputElement).checked,
    ).toBe(true);
    expect(swatchOf('Couleur par défaut')).toBe('rgb(72, 109, 240)');
  });

  it('offers shade, parent color and custom to a sub-group with their swatches', () => {
    renderField({
      root: false,
      swatches: { AUTO: '#7B96F4', PARENT: '#486DF0' },
      mode: 'PARENT',
    });

    expect(screen.getByRole('radio', { name: /Nuance du groupe parent/ })).toBeTruthy();
    expect(
      (screen.getByRole('radio', { name: /Couleur du groupe parent/ }) as HTMLInputElement).checked,
    ).toBe(true);
    expect(swatchOf('Nuance du groupe parent')).toBe('rgb(123, 150, 244)');
    expect(swatchOf('Couleur du groupe parent')).toBe('rgb(72, 109, 240)');
  });

  it('announces the shade is assigned on save when its slot is not known yet', () => {
    renderField({ root: false, swatches: { AUTO: null, PARENT: '#486DF0' } });

    expect(screen.getByText("Nuance attribuée à l'enregistrement".replace("'", '’'))).toBeTruthy();
  });

  it('shows the color input and the reset button only in custom mode', () => {
    const onChange = renderField({ mode: 'CUSTOM', color: '#112233' });

    expect((screen.getByLabelText('Couleur du groupe') as HTMLInputElement).value).toBe('#112233');
    fireEvent.click(screen.getByRole('button', { name: 'Rétablir la couleur par défaut' }));
    expect(onChange).toHaveBeenCalledWith({ mode: 'AUTO', color: null });
  });

  it('resets a sub-group to the parent shade', () => {
    const onChange = renderField({ root: false, mode: 'CUSTOM', color: '#112233' });

    fireEvent.click(screen.getByRole('button', { name: 'Rétablir la couleur par défaut' }));
    expect(onChange).toHaveBeenCalledWith({ mode: 'AUTO', color: null });
  });

  it('hides the input and the reset button outside custom mode', () => {
    renderField();

    expect(screen.queryByLabelText('Couleur du groupe')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Rétablir la couleur par défaut' })).toBeNull();
  });

  it('switches to custom with a starting color and reports typed colors in canonical case', () => {
    const onChange = renderField({ customStart: '#486DF0' });

    fireEvent.click(screen.getByRole('radio', { name: 'Personnalisée' }));
    expect(onChange).toHaveBeenCalledWith({ mode: 'CUSTOM', color: '#486DF0' });
  });

  it('canonicalizes the picked color', () => {
    const onChange = renderField({ mode: 'CUSTOM', color: '#112233' });

    fireEvent.change(screen.getByLabelText('Couleur du groupe'), { target: { value: '#ffffff' } });
    expect(onChange).toHaveBeenCalledWith({ mode: 'CUSTOM', color: '#FFFFFF' });
  });

  it('flags the color input as invalid with its message', () => {
    renderField({ mode: 'CUSTOM', color: '#112233', invalid: true });

    expect(screen.getByLabelText('Couleur du groupe').getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByLabelText('Couleur du groupe').getAttribute('aria-describedby')).toBe(
      'account-group-color-error',
    );
  });
});
