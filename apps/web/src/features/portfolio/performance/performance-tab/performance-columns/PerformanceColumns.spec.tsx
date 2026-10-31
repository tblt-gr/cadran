import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { PerformanceColumns, usePerformanceColumns } from './PerformanceColumns';

function Picker({ accountId = 'account-1' }: { accountId?: string }) {
  const columns = usePerformanceColumns(accountId);
  return <PerformanceColumns selected={columns.selected} onChange={columns.change} />;
}
afterEach(() => {
  cleanup();
  localStorage.clear();
});
it('restores column choices for the same account without affecting another account', () => {
  const view = render(<Picker />);
  fireEvent.click(screen.getByRole('button', { name: 'Colonnes affichées' }));
  fireEvent.click(screen.getByRole('menuitemcheckbox', { name: 'Taxes' }));
  view.unmount();
  const restored = render(<Picker />);
  fireEvent.click(screen.getByRole('button', { name: 'Colonnes affichées' }));
  expect(screen.getByRole('menuitemcheckbox', { name: 'Taxes' }).getAttribute('aria-checked')).toBe(
    'false',
  );
  restored.unmount();
  render(<Picker accountId="account-2" />);
  fireEvent.click(screen.getByRole('button', { name: 'Colonnes affichées' }));
  expect(screen.getByRole('menuitemcheckbox', { name: 'Taxes' }).getAttribute('aria-checked')).toBe(
    'true',
  );
});
it('recovers from invalid browser storage and preserves at least one column', () => {
  localStorage.setItem('cadran:performance-columns:v1:account-1', '["unknown", "taxes"]');
  render(<Picker />);
  fireEvent.click(screen.getByRole('button', { name: 'Colonnes affichées' }));
  expect(
    (screen.getByRole('menuitemcheckbox', { name: 'Taxes' }) as HTMLInputElement).disabled,
  ).toBe(true);
});

it('closes with Escape and outside click, and restores trigger focus', () => {
  render(<Picker />);
  const trigger = screen.getByRole('button', { name: 'Colonnes affichées' });
  expect(trigger.className).toContain('icon-button');
  expect(trigger.textContent).toBe('');
  expect(screen.queryByRole('menu')).toBeNull();
  fireEvent.click(trigger);
  expect(screen.getByRole('menu')).toBeTruthy();
  fireEvent.keyDown(window, { key: 'End' });
  expect(document.activeElement?.textContent).toBe('MWR (mois)');
  fireEvent.keyDown(window, { key: 'Escape' });
  expect(screen.queryByRole('menu')).toBeNull();
  expect(document.activeElement).toBe(trigger);
  fireEvent.click(trigger);
  fireEvent.pointerDown(document.body);
  expect(screen.queryByRole('menu')).toBeNull();
});
