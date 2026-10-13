import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { TabsNav, type TabsNavItem } from './TabsNav';

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

  describe('when the items overflow', () => {
    const months: TabsNavItem[] = ['2026-10', '2026-09', '2026-08', '2026-07'].map((id) => ({
      id,
      label: id,
      onSelect: () => undefined,
      active: id === '2026-10',
    }));

    // jsdom has no layout: give the strip and its tabs the geometry a browser would measure.
    function stubBox(node: HTMLElement, box: Record<string, number>) {
      for (const [key, value] of Object.entries(box)) {
        Object.defineProperty(node, key, { configurable: true, value, writable: true });
      }
    }

    function strip() {
      return screen.getByRole('navigation', { name: 'Mois' });
    }

    function scrollButton(name: string) {
      return screen.getByRole<HTMLButtonElement>('button', { name });
    }

    it('offers no scroll button while everything fits', () => {
      render(<TabsNav items={months} label="Mois" variant="secondary" />);
      expect(screen.queryByRole('button', { name: 'Défiler vers la gauche' })).toBeNull();
      expect(screen.queryByRole('button', { name: 'Défiler vers la droite' })).toBeNull();
    });

    it('scrolls with the circular buttons and disables each one at its end', () => {
      render(<TabsNav items={months} label="Mois" variant="secondary" />);
      stubBox(strip(), { clientWidth: 100, scrollLeft: 0, scrollWidth: 300 });
      fireEvent.scroll(strip());

      expect(scrollButton('Défiler vers la gauche').disabled).toBe(true);
      expect(scrollButton('Défiler vers la droite').disabled).toBe(false);

      fireEvent.click(scrollButton('Défiler vers la droite'));
      expect(strip().scrollLeft).toBe(80);
      fireEvent.scroll(strip());
      expect(scrollButton('Défiler vers la gauche').disabled).toBe(false);

      strip().scrollLeft = 200;
      fireEvent.scroll(strip());
      expect(scrollButton('Défiler vers la droite').disabled).toBe(true);
      fireEvent.click(scrollButton('Défiler vers la gauche'));
      expect(strip().scrollLeft).toBe(120);
    });

    it('brings the active tab into view when it changes', () => {
      const { rerender } = render(<TabsNav items={months} label="Mois" variant="secondary" />);
      stubBox(strip(), { clientWidth: 100, scrollLeft: 0, scrollWidth: 300 });
      stubBox(screen.getByRole('button', { name: '2026-07' }), {
        offsetLeft: 250,
        offsetWidth: 40,
      });

      rerender(
        <TabsNav
          items={months.map((item) => ({ ...item, active: item.id === '2026-07' }))}
          label="Mois"
          variant="secondary"
        />,
      );
      expect(strip().scrollLeft).toBe(190);
    });
  });
});
