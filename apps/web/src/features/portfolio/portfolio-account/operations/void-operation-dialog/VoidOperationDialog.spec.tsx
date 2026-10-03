import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { VoidOperationDialog } from './VoidOperationDialog';

afterEach(cleanup);

describe('VoidOperationDialog', () => {
  it('refuses to void without a reason', () => {
    const onConfirm = vi.fn();
    render(<VoidOperationDialog onConfirm={onConfirm} pending={false} />);
    fireEvent.click(screen.getByRole('button', { name: 'Annuler l’opération' }));
    expect(onConfirm).not.toHaveBeenCalled();
    expect(screen.getByText('Le motif est obligatoire.')).toBeTruthy();
  });

  it('sends the trimmed reason and caps it at 200 characters', () => {
    const onConfirm = vi.fn();
    render(<VoidOperationDialog onConfirm={onConfirm} pending={false} />);
    const reason = screen.getByLabelText('Motif') as HTMLTextAreaElement;
    expect(reason.maxLength).toBe(200);
    expect(reason.hasAttribute('data-autofocus')).toBe(true);
    fireEvent.change(reason, { target: { value: '  Doublon  ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Annuler l’opération' }));
    expect(onConfirm).toHaveBeenCalledWith('Doublon');
  });

  it('shows a server error', () => {
    render(<VoidOperationDialog error="Déjà annulée." onConfirm={vi.fn()} pending={false} />);
    expect(screen.getByRole('alert').textContent).toBe('Déjà annulée.');
  });
});
