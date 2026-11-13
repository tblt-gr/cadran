import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { LedgerPanelHeader } from './LedgerPanelHeader';

function renderHeader(props: Partial<Parameters<typeof LedgerPanelHeader>[0]>) {
  return render(
    <LedgerPanelHeader
      actionsAllowed
      axis={null}
      kind="income"
      titleId="title"
      totalState="loading"
      {...props}
    />,
  );
}

describe('LedgerPanelHeader title', () => {
  afterEach(() => {
    cleanup();
    window.history.pushState({}, '', '/');
  });

  it('links the income title to the income transactions of the month', () => {
    renderHeader({ kind: 'income', month: '2026-10' });

    const link = screen.getByRole('link', { name: 'Revenus du mois' });
    expect(link.getAttribute('href')).toBe(
      '/transactions?nature=INCOME&categoryType=INCOME&period=month&month=2026-10&budgetMonth=2026-10',
    );
    expect(link.closest('h3')?.id).toBe('title');
    expect(link.hasAttribute('aria-label')).toBe(false);
    expect(link.querySelector('.sr-only')?.textContent).toBe('du mois');
  });

  it('carries the selected axis on the expense link', () => {
    renderHeader({ axis: 'ESSENTIAL', kind: 'expense', month: '2026-10' });

    expect(screen.getByRole('link', { name: 'Dépenses du mois' }).getAttribute('href')).toContain(
      'categoryType=EXPENSE&axis=ESSENTIAL&period=month&month=2026-10&budgetMonth=2026-10',
    );
  });

  it('links the transfers title to the transfers of the month', () => {
    renderHeader({ kind: 'account', month: '2026-10' });

    expect(screen.getByRole('link', { name: 'Virements du mois' }).getAttribute('href')).toBe(
      '/transactions?nature=TRANSFER&period=month&month=2026-10&budgetMonth=2026-10',
    );
  });

  it('navigates without a page load on a plain click', () => {
    renderHeader({ kind: 'income', month: '2026-10' });

    fireEvent.click(screen.getByRole('link', { name: 'Revenus du mois' }));

    expect(window.location.pathname + window.location.search).toBe(
      '/transactions?nature=INCOME&categoryType=INCOME&period=month&month=2026-10&budgetMonth=2026-10',
    );
  });

  it('shows plain text, not a link, while the page is loading', () => {
    renderHeader({ kind: 'income' });

    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByRole('heading', { name: 'Revenus' })).toBeTruthy();
  });
});
