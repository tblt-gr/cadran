import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { Icon } from '@/components/ui/icon/Icon';
import { MobileHeaderActions } from './MobileHeaderActions';

afterEach(cleanup);

describe('MobileHeaderActions', () => {
  it('keeps the first action as an icon with its name and moves the others into a menu', () => {
    const onThird = vi.fn();
    render(
      <MobileHeaderActions>
        <button className="primary-action" type="button">
          <Icon name="add" size={18} />
          Nouvelle transaction
        </button>
        <a className="secondary-action" href="/somewhere">
          <Icon name="folder" size={18} />
          Dossier
        </a>
        <button onClick={onThird} type="button">
          <Icon name="edit" size={18} />
          Troisième
        </button>
      </MobileHeaderActions>,
    );

    const first = screen.getByRole('button', { name: 'Nouvelle transaction' });
    expect(first.textContent).toBe('');
    expect(first.getAttribute('title')).toBe('Nouvelle transaction');
    expect(screen.queryByRole('link', { name: 'Dossier' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Troisième' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Autres actions de la page' }));
    expect(screen.getByRole('button', { name: 'Dossier' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Troisième' }));
    expect(onThird).toHaveBeenCalledOnce();
  });

  it('shows no menu when there is a single action', () => {
    render(
      <MobileHeaderActions>
        <button type="button">
          <Icon name="add" size={18} />
          Ajouter
        </button>
      </MobileHeaderActions>,
    );
    expect(screen.queryByRole('button', { name: 'Autres actions de la page' })).toBeNull();
  });
});
