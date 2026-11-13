import { useTranslation } from 'react-i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';
import { monthHref, type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { budgetContextHref, budgetRegisterHref } from './budgetNavigation';

export type BudgetTab = 'budget' | 'transactions' | 'categories' | 'recurrences';

/**
 * Tabs shared by the budget follow-up and the pages it relies on. `month` is the budget month the
 * tabs return to; null when the page has no month (categories, recurrences opened directly).
 */
export function BudgetNavigation({
  current,
  month,
  axis = null,
  budgetHref,
}: {
  current: BudgetTab;
  month: string | null;
  axis?: BudgetAxis | null;
  budgetHref?: string;
}) {
  const { t } = useTranslation();
  const hrefs: Record<BudgetTab, string> = {
    budget: budgetHref ?? (month ? monthHref(month, axis) : '/budget'),
    transactions: month ? budgetRegisterHref(month, axis) : '/transactions',
    categories: budgetContextHref('/transactions/categories', month, axis),
    recurrences: budgetContextHref('/transactions/recurrences', month, axis),
  };
  // The current tab keeps its own URL so following it never drops filters.
  hrefs[current] = `${window.location.pathname}${window.location.search}`;
  const labels: Record<BudgetTab, string> = {
    budget: t('budget.followUp.navigation'),
    transactions: t('navigation.transactions'),
    categories: t('routes.categories'),
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
