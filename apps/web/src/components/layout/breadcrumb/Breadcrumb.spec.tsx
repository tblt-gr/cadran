import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { useBreadcrumbAccount } from './BreadcrumbContext';

function AccountLabel({
  path,
  label,
  pending = false,
}: {
  path: string;
  label: string | null;
  pending?: boolean;
}) {
  useBreadcrumbAccount(path, label, pending);
  return null;
}

afterEach(cleanup);

describe('global breadcrumb', () => {
  it.each([
    ['/', ['Accueil']],
    ['/accounts', ['Accueil', 'Comptes']],
    ['/accounts/groups', ['Accueil', 'Comptes', 'Groupes de comptes']],
    ['/portfolios/instruments', ['Accueil', 'Comptes', 'Instruments']],
    ['/transactions', ['Accueil', 'Transactions']],
    ['/transactions/recurrences', ['Accueil', 'Transactions', 'Récurrences']],
    ['/transactions/categories', ['Accueil', 'Transactions', 'Catégories']],
    [
      '/transactions/categories/rules',
      ['Accueil', 'Transactions', 'Catégories', 'Règles de catégorisation'],
    ],
    ['/categories/rules', ['Accueil', 'Transactions', 'Catégories', 'Règles de catégorisation']],
    ['/budget', ['Accueil', 'Budget']],
    ['/budget/2026/03', ['Accueil', 'Budget', '2026', 'Mars']],
    ['/budget/2026', ['Accueil', 'Budget', '2026', 'Rapport']],
    ['/budget/2026-03', ['Accueil', 'Page introuvable']],
    ['/budget/plans', ['Accueil', 'Budget', 'Plans budgétaires']],
    ['/budget/plans/plan-1', ['Accueil', 'Budget', 'Plans budgétaires', 'Plan budgétaire']],
    ['/reports', ['Accueil', 'Page introuvable']],
    ['/goals', ['Accueil', 'Objectifs']],
    ['/parameters/catalog', ['Accueil', 'Paramètres', 'Catalogue produits']],
    ['/parameters/product-models', ['Accueil', 'Paramètres', 'Modèles de produits']],
    ['/parameters/profile', ['Accueil', 'Paramètres', 'Profil']],
    ['/parameters/metric-policy', ['Accueil', 'Paramètres', 'Politique d’indicateurs']],
    ['/unknown', ['Accueil', 'Page introuvable']],
    ['/budget/extra/path', ['Accueil', 'Page introuvable']],
  ])('shows the hierarchy on direct entry to %s', (path, labels) => {
    render(
      <AppShell path={path} setPath={vi.fn()}>
        <p>Content</p>
      </AppShell>,
    );
    const nav = screen.getByRole('navigation', { name: 'Fil d’Ariane' });
    expect(
      within(nav)
        .getAllByRole('listitem')
        .map((item) => item.textContent?.replaceAll('/', '').trim()),
    ).toEqual(labels);
    const current = nav.querySelector('[aria-current="page"]');
    expect(current?.textContent).toBe(labels.at(-1));
    expect(current?.tagName).toBe('SPAN');
    expect(within(nav).getByRole('list').tagName).toBe('OL');
  });

  it('hangs a portfolio under Comptes and keeps its name as the current page', () => {
    const navigate = vi.fn();
    render(
      <AppShell path="/portfolios/first" setPath={navigate}>
        <AccountLabel path="/portfolios/first" label="PEA personnel" />
      </AppShell>,
    );
    const nav = screen.getByRole('navigation', { name: 'Fil d’Ariane' });
    expect(
      within(nav)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual(['/', '/accounts']);
    expect(within(nav).getByText('PEA personnel').getAttribute('aria-current')).toBe('page');
    const parent = within(nav).getByRole('link', { name: 'Comptes' });
    fireEvent.click(parent);
    expect(navigate).toHaveBeenCalledWith('/accounts');
    expect(nav.querySelectorAll('[aria-hidden="true"]').length).toBe(2);
  });

  it('scopes labels to the current route and falls back safely after errors', () => {
    const view = (path: string, labelPath: string, label: string | null, pending = false) => (
      <AppShell path={path} setPath={vi.fn()}>
        <AccountLabel path={labelPath} label={label} pending={pending} />
      </AppShell>
    );
    const { rerender } = render(view('/accounts/first', '/accounts/first', 'Secret account'));
    expect(
      within(screen.getByRole('navigation', { name: 'Fil d’Ariane' }))
        .getByText('Secret account')
        .getAttribute('aria-current'),
    ).toBe('page');
    rerender(view('/portfolios/second', '/accounts/first', 'Secret account'));
    const nav = screen.getByRole('navigation', { name: 'Fil d’Ariane' });
    expect(within(nav).queryByText('Secret account')).toBeNull();
    expect(within(nav).getByRole('link', { name: 'Accueil' })).toBeTruthy();
    expect(within(nav).getByRole('link', { name: 'Comptes' })).toBeTruthy();
    expect(within(nav).getByText('Chargement du compte').getAttribute('aria-busy')).toBe('true');
    rerender(view('/portfolios/second', '/portfolios/second', null));
    expect(within(nav).getByText('Compte').getAttribute('aria-current')).toBe('page');
    expect(within(nav).queryByText('Secret account')).toBeNull();
    expect(within(nav).queryByRole('status')).toBeNull();
  });
});
