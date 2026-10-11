import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TabsNav } from './TabsNav';

describe('TabsNav', () => {
  afterEach(cleanup);

  it('renders links, marks the active one and keeps a disabled tab out of the tab order', () => {
    render(
      <TabsNav
        items={[
          { id: 'a', label: 'Alpha', href: '/a', active: true },
          { id: 'b', label: 'Beta', href: '/b' },
          { id: 'c', label: 'Gamma', href: '/c', disabled: true, title: 'Indisponible' },
        ]}
        label="Sections"
      />,
    );
    expect(screen.getByRole('navigation', { name: 'Sections' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Alpha' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Beta' }).getAttribute('aria-current')).toBeNull();
    expect(screen.queryByRole('link', { name: 'Gamma' })).toBeNull();
    expect(screen.getByText('Gamma').getAttribute('aria-disabled')).toBe('true');
  });

  it('renders buttons for in-page selection and flags the current item without colour', () => {
    const onSelect = vi.fn();
    render(
      <TabsNav
        items={[
          { id: 'x', label: 'X', onSelect, current: true },
          { id: 'y', label: 'Y', onSelect: () => undefined, active: true },
        ]}
        label="Mois"
        variant="secondary"
      />,
    );
    const x = screen.getByRole('button', { name: 'X' });
    expect(x.getAttribute('data-current')).toBe('true');
    expect(screen.getByRole('button', { name: 'Y' }).getAttribute('aria-current')).toBe('page');
    fireEvent.click(x);
    expect(onSelect).toHaveBeenCalledTimes(1);
  });
});
