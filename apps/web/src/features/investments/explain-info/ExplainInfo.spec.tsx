import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ExplainInfo } from './ExplainInfo';

afterEach(cleanup);

describe('ExplainInfo', () => {
  it('opens a modal titled with the figure, closes it and returns focus to the button', async () => {
    render(<ExplainInfo buttonLabel="Expliquer : Valeur" text="Cours manquant." title="Valeur" />);
    const button = screen.getByRole('button', { name: 'Expliquer : Valeur' });
    button.focus();
    expect(screen.queryByRole('dialog')).toBeNull();
    fireEvent.click(button);
    const dialog = screen.getByRole('dialog', { name: 'Valeur' });
    expect(within(dialog).getByText('Cours manquant.')).toBeTruthy();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(button));
  });
});
