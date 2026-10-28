import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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

const input = () => screen.getByLabelText('Couleur du groupe') as HTMLInputElement;
const resetName = 'Rétablir la couleur par défaut';
const useParentName = 'Utiliser la couleur du groupe parent';

describe('GroupColorField', () => {
  afterEach(cleanup);

  it('offers a root only the color input, with no radio and no parent action', () => {
    renderField();

    expect(screen.getByRole('group', { name: 'Couleur' })).toBeTruthy();
    expect(screen.queryAllByRole('radio')).toEqual([]);
    expect(input().value.toUpperCase()).toBe('#486DF0');
    expect(screen.queryByRole('button', { name: resetName })).toBeNull();
    expect(screen.queryByRole('button', { name: useParentName })).toBeNull();
  });

  it('shows the shade of a sub-group in the input and offers the parent color below', () => {
    const onChange = renderField({
      root: false,
      swatches: { AUTO: '#7B96F4', PARENT: '#486DF0' },
    });

    expect(input().value.toUpperCase()).toBe('#7B96F4');
    expect(screen.getByText('Nuance du groupe parent')).toBeTruthy();
    expect(screen.queryByRole('button', { name: resetName })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: useParentName }));
    expect(onChange).toHaveBeenCalledWith({ mode: 'PARENT', color: null });
  });

  it('previews the parent color and announces the shade is assigned on save', () => {
    renderField({ root: false, swatches: { AUTO: null, PARENT: '#486DF0' } });

    expect(input().value.toUpperCase()).toBe('#486DF0');
    expect(screen.getByText('Nuance attribuée à l’enregistrement')).toBeTruthy();
  });

  it('shows the parent color with a reset back to the shade', () => {
    const onChange = renderField({
      root: false,
      mode: 'PARENT',
      swatches: { AUTO: '#7B96F4', PARENT: '#486DF0' },
    });

    expect(input().value.toUpperCase()).toBe('#486DF0');
    expect(screen.getByText('Couleur du groupe parent')).toBeTruthy();
    expect(screen.queryByRole('button', { name: useParentName })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: resetName }));
    expect(onChange).toHaveBeenCalledWith({ mode: 'AUTO', color: null });
  });

  it('resets a custom color to the default mode', () => {
    const onChange = renderField({ mode: 'CUSTOM', color: '#112233' });

    expect(input().value).toBe('#112233');
    fireEvent.click(screen.getByRole('button', { name: resetName }));
    expect(onChange).toHaveBeenCalledWith({ mode: 'AUTO', color: null });
  });

  it('switches to custom with the picked color in canonical case', () => {
    const onChange = renderField({ root: false, swatches: { AUTO: '#7B96F4', PARENT: '#486DF0' } });

    fireEvent.change(input(), { target: { value: '#ffffff' } });
    expect(onChange).toHaveBeenCalledWith({ mode: 'CUSTOM', color: '#FFFFFF' });
  });

  it('flags the color input as invalid with its message', () => {
    renderField({ mode: 'CUSTOM', color: '#112233', invalid: true });

    expect(input().getAttribute('aria-invalid')).toBe('true');
    expect(input().getAttribute('aria-describedby')).toBe('account-group-color-error');
  });
});
