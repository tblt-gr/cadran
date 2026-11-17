import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { Icon } from '@/components/ui/icon/Icon';
import { MobileHeaderActions } from './MobileHeaderActions';

afterEach(cleanup);

describe('MobileHeaderActions', () => {
  it('keeps two actions as icons with their names and moves the others into a menu', () => {
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
        <button type="button">
          <Icon name="archive" size={18} />
          Quatrième
        </button>
      </MobileHeaderActions>,
    );

    const first = screen.getByRole('button', { name: 'Nouvelle transaction' });
    expect(first.textContent).toBe('');
    expect(first.getAttribute('title')).toBe('Nouvelle transaction');
    expect(screen.getByRole('link', { name: 'Dossier' }).textContent).toBe('');
    expect(screen.queryByRole('button', { name: 'Troisième' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Autres actions de la page' }));
    expect(screen.getByRole('button', { name: 'Quatrième' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Troisième' }));
    expect(onThird).toHaveBeenCalledOnce();
  });

  it('keeps three actions inline and opens a menu only from four on', () => {
    render(
      <MobileHeaderActions>
        <button type="button">
          <Icon name="add" size={18} />
          Un
        </button>
        <button type="button">
          <Icon name="edit" size={18} />
          Deux
        </button>
        <button type="button">
          <Icon name="archive" size={18} />
          Trois
        </button>
      </MobileHeaderActions>,
    );
    expect(screen.getByRole('button', { name: 'Trois' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Autres actions de la page' })).toBeNull();
  });

  it('shows no menu when two actions or fewer are left', () => {
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
