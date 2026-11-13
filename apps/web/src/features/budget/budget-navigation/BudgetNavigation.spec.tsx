import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { BudgetNavigation } from './BudgetNavigation';

function hrefs() {
  return Object.fromEntries(
    screen
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')] as const),
  );
}

describe('BudgetNavigation', () => {
  afterEach(() => {
    cleanup();
    window.history.replaceState({}, '', '/');
  });

  it('offers the four budget tabs and keeps the budget month on each of them', () => {
    window.history.replaceState({}, '', '/budget/2026/09?axis=FIXED');
    render(<BudgetNavigation current="budget" month="2026-09" axis="FIXED" />);

    expect(screen.getByRole('link', { name: 'Suivi du budget' }).getAttribute('aria-current')).toBe(
      'page',
    );
    expect(hrefs()).toEqual({
      'Suivi du budget': '/budget/2026/09?axis=FIXED',
      Transactions: '/transactions?period=month&month=2026-09&budgetMonth=2026-09&budgetAxis=FIXED',
      Catégories: '/transactions/categories?budgetMonth=2026-09&budgetAxis=FIXED',
      Récurrences: '/transactions/recurrences?budgetMonth=2026-09&budgetAxis=FIXED',
    });
  });

  it('falls back to the current month routes when the page has no budget month', () => {
    window.history.replaceState({}, '', '/transactions/categories');
    render(<BudgetNavigation current="categories" month={null} />);

    expect(screen.getByRole('link', { name: 'Catégories' }).getAttribute('aria-current')).toBe(
      'page',
    );
    expect(hrefs()).toEqual({
      'Suivi du budget': '/budget',
      Transactions: '/transactions',
      Catégories: '/transactions/categories',
      Récurrences: '/transactions/recurrences',
    });
  });
});
