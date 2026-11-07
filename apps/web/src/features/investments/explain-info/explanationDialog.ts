import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { expect } from 'vitest';

/**
 * Test helper: opens the explanation of an "i" button, checks the modal title and text, closes it
 * and checks focus returned to the button.
 */
export async function expectExplanation(
  button: HTMLElement,
  title: string | RegExp,
  text: string | RegExp,
) {
  button.focus();
  fireEvent.click(button);
  const dialog = screen.getByRole('dialog', { name: title });
  expect(within(dialog).getByText(text)).toBeTruthy();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }));
  expect(screen.queryByRole('dialog')).toBeNull();
  await waitFor(() => expect(document.activeElement).toBe(button));
}
