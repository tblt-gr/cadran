import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '../../../i18n';
import { AppShell } from './AppShell';

function renderShell() {
  return render(
    <AppShell
      freshnessLabel="Mis à jour il y a 4 jours"
      headerDate="Samedi 29 août"
      path="/"
      setPath={vi.fn()}
    >
      <p>Contenu</p>
    </AppShell>,
  );
}

describe('AppShell', () => {
  afterEach(() => {
    cleanup();
  });

  it('closes the complementary navigation and restores focus to its trigger', async () => {
    renderShell();

    const moreButton = screen.getByRole('button', { name: 'Plus' });
    fireEvent.click(moreButton);
    const sheet = screen.getByRole('dialog', { name: 'Navigation complémentaire' });
    expect(within(sheet).getByRole('link', { name: 'Objectifs' })).toBeTruthy();

    fireEvent.click(within(sheet).getByRole('button', { name: 'Fermer' }));

    expect(screen.queryByRole('dialog', { name: 'Navigation complémentaire' })).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(moreButton));
  });

  it('gives explicit translated feedback for foundation search and add actions', () => {
    renderShell();

    fireEvent.click(screen.getByRole('button', { name: 'Rechercher' }));
    const searchDialog = screen.getByRole('dialog', { name: 'Rechercher dans Cadran' });
    expect(within(searchDialog).getByRole('status').textContent).toContain(
      'Recherche bientôt disponible',
    );
    fireEvent.keyDown(document, { key: 'Escape' });

    fireEvent.click(screen.getAllByRole('button', { name: 'Ajouter' })[0]);
    const addDialog = screen.getByRole('dialog', { name: 'Ajouter une donnée' });
    expect(within(addDialog).getByRole('status').textContent).toContain('Ajout bientôt disponible');
  });
});
