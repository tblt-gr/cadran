import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { ModalActions } from './ModalActions';
import { ModalForm } from './ModalForm';

afterEach(cleanup);

it('keeps scrolling fields and fixed actions inside the same native form', () => {
  const submit = vi.fn((event) => event.preventDefault());
  render(
    <ModalForm aria-label="Transaction" className="caller" onSubmit={submit}>
      <label>
        Libellé
        <input name="label" defaultValue="Courses" />
      </label>
      <ModalActions>
        <button type="submit">Enregistrer</button>
      </ModalActions>
    </ModalForm>,
  );
  const form = screen.getByRole('form', { name: 'Transaction' }) as HTMLFormElement;
  expect(form.classList.contains('caller')).toBe(true);
  const field = screen.getByRole('textbox');
  const save = screen.getByRole('button', { name: 'Enregistrer' }) as HTMLButtonElement;
  expect(field.closest('[data-form-content]')).not.toBeNull();
  expect(save.closest('[data-form-content]')).toBeNull();
  expect(save.form).toBe(form);
  expect(new FormData(form).get('label')).toBe('Courses');
  fireEvent.click(save);
  expect(submit).toHaveBeenCalledOnce();
});

it('preserves disabled fields, error messages and the order of the form content', () => {
  render(
    <ModalForm>
      <p role="alert">Enregistrement refusé</p>
      <fieldset disabled>
        <label>
          Montant
          <input name="amount" defaultValue="10" />
        </label>
      </fieldset>
      <ModalActions>
        <button disabled type="submit">
          Enregistrer
        </button>
      </ModalActions>
    </ModalForm>,
  );
  expect(screen.getByRole('alert').closest('[data-form-content]')).not.toBeNull();
  expect((screen.getByRole('button') as HTMLButtonElement).disabled).toBe(true);
  expect(screen.getByRole('textbox').closest('fieldset')?.disabled).toBe(true);
});
