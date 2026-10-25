import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { Tabs } from './Tabs';

afterEach(cleanup);

function renderTabs() {
  render(
    <Tabs
      label="Colonnes"
      tabs={[
        { id: 'a', label: 'Alpha', content: <input aria-label="first" type="checkbox" /> },
        { id: 'b', label: 'Beta', content: <input aria-label="second" type="checkbox" /> },
        { id: 'c', label: 'Gamma', content: <p>third</p> },
      ]}
    />,
  );
}

describe('Tabs', () => {
  it('exposes a tablist with one selected tab and a roving tabindex', () => {
    renderTabs();
    const tabs = screen.getAllByRole('tab');
    expect(screen.getByRole('tablist', { name: 'Colonnes' })).toBeTruthy();
    expect(tabs.map((tab) => tab.getAttribute('aria-selected'))).toEqual([
      'true',
      'false',
      'false',
    ]);
    expect(tabs.map((tab) => tab.getAttribute('tabindex'))).toEqual(['0', '-1', '-1']);
    expect(screen.getByRole('tabpanel', { name: 'Alpha' })).toBeTruthy();
  });

  it('moves with the arrow keys, wrapping, Home and End, focusing the new tab', () => {
    renderTabs();
    const [alpha, beta, gamma] = screen.getAllByRole('tab') as HTMLElement[];
    alpha!.focus();
    fireEvent.keyDown(alpha!, { key: 'ArrowRight' });
    expect(document.activeElement).toBe(beta);
    expect(beta!.getAttribute('aria-selected')).toBe('true');
    fireEvent.keyDown(beta!, { key: 'End' });
    expect(document.activeElement).toBe(gamma);
    fireEvent.keyDown(gamma!, { key: 'ArrowRight' });
    expect(document.activeElement).toBe(alpha);
    fireEvent.keyDown(alpha!, { key: 'ArrowLeft' });
    expect(document.activeElement).toBe(gamma);
    fireEvent.keyDown(gamma!, { key: 'Home' });
    expect(document.activeElement).toBe(alpha);
  });

  it('keeps every panel mounted so a checked box survives a tab change', () => {
    renderTabs();
    fireEvent.click(screen.getByLabelText('first'));
    fireEvent.click(screen.getByRole('tab', { name: 'Beta' }));
    expect(screen.getByLabelText('first').closest('[hidden]')).not.toBeNull();
    fireEvent.click(screen.getByRole('tab', { name: 'Alpha' }));
    expect((screen.getByLabelText('first') as HTMLInputElement).checked).toBe(true);
  });
});
