import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { budgetContextHref, budgetRegisterHref } from './budgetNavigation';

export type BudgetTab = 'budget' | 'transactions' | 'categories' | 'rules' | 'recurrences';

/**
 * Tabs shared by the budget follow-up and the pages it relies on. `month` is the context carried
 * to sibling tabs; null when the page has no month (categories, recurrences opened directly).
 */
export function BudgetNavigation({
  current,
  month,
  axis = null,
}: {
  current: BudgetTab;
  month: string | null;
  axis?: BudgetAxis | null;
}) {
  const { t } = useTranslation();
  const hrefs: Record<BudgetTab, string> = {
    budget: '/budget',
    transactions: month ? budgetRegisterHref(month, axis) : '/transactions',
    categories: budgetContextHref('/transactions/categories', month, axis),
    rules: budgetContextHref('/transactions/categories/rules', month, axis),
    recurrences: budgetContextHref('/transactions/recurrences', month, axis),
  };
  // The budget tab always returns to the workspace's current month via /budget.
  // Other current tabs keep their own URL so following them never drops filters.
  if (current !== 'budget') hrefs[current] = `${window.location.pathname}${window.location.search}`;
  const labels: Record<BudgetTab, string> = {
    budget: t('budget.followUp.navigation'),
    transactions: t('navigation.transactions'),
    categories: t('routes.categories'),
    rules: t('budget.followUp.rules'),
    recurrences: t('routes.recurrences'),
  };
  return (
    <TabsNav
      items={(Object.keys(hrefs) as BudgetTab[]).map((tab) => ({
        id: tab,
        active: current === tab,
        href: hrefs[tab],
        label: labels[tab],
      }))}
      label={t('budget.followUp.navigationLabel')}
    />
  );
}
