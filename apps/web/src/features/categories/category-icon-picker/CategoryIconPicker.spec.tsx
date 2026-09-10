import { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { CATEGORY_ICONS } from '@/features/categories/category-icons';
import { CategoryIconPicker } from './CategoryIconPicker';

function Picker({ initial = '' }: { initial?: string }) {
  const [value, onChange] = useState(initial);
  return <CategoryIconPicker onChange={onChange} value={value} />;
}

describe('CategoryIconPicker', () => {
  afterEach(cleanup);

  it('exposes focusable named radios and preserves native Space and arrow keys after a search', () => {
    render(<Picker initial="heart" />);
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'coeur' } });
    const health = screen.getByRole('radio', { name: 'Cœur', checked: true });
    const none = screen.getByRole('radio', { name: 'Sans icône', checked: false });
    for (const radio of [health, none]) {
      expect((radio as HTMLInputElement).disabled).toBe(false);
      expect(radio.tabIndex).toBe(0);
      expect(radio.getAttribute('name')).toBe(health.getAttribute('name'));
      radio.focus();
      expect(document.activeElement).toBe(radio);
      expect(radio.closest('label')?.contains(document.activeElement)).toBe(true);
      // JSDOM does not implement native radio key defaults; none may be prevented here.
      for (const key of [' ', 'ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp']) {
        expect(fireEvent.keyDown(radio, { key })).toBe(true);
        expect(fireEvent.keyUp(radio, { key })).toBe(true);
      }
      expect(document.activeElement).toBe(radio);
    }
  });

  it('searches French labels without accents and lets users explicitly clear the selection', () => {
    render(<Picker />);
    fireEvent.change(screen.getByRole('searchbox', { name: 'Rechercher une icône' }), {
      target: { value: 'coeur' },
    });
    const health = screen.getByRole('radio', { name: 'Cœur' });
    fireEvent.click(health);
    expect((health as HTMLInputElement).checked).toBe(true);
    expect(screen.queryByRole('radio', { name: 'Couverts' })).toBeNull();
    fireEvent.click(screen.getByRole('radio', { name: 'Sans icône' }));
    expect((screen.getByRole('radio', { name: 'Sans icône' }) as HTMLInputElement).checked).toBe(
      true,
    );
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'zzzz' } });
    expect(screen.getByRole('status').textContent).toContain('Aucune icône');
  });

  it('offers the whole catalogue as one flat grid with no sections', () => {
    render(<Picker />);

    // One cell per glyph, plus the explicit "no icon" cell.
    expect(screen.getAllByRole('radio')).toHaveLength(CATEGORY_ICONS.length + 1);
    expect(screen.getByRole('status').textContent).toContain(
      `${CATEGORY_ICONS.length} icônes disponibles.`,
    );
    expect(screen.getAllByRole('group')).toHaveLength(1);
  });

  it('explains an unsupported historical key and permits an explicit replacement', () => {
    render(<Picker initial="legacy-icon" />);
    expect(screen.getByText(/Cette ancienne icône/)).toBeTruthy();
    fireEvent.click(screen.getByRole('radio', { name: 'Couverts' }));
    expect(screen.queryByText(/Cette ancienne icône/)).toBeNull();
  });
});
