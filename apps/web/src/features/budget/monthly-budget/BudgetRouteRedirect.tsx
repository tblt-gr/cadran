import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

export function BudgetRouteRedirect({ href }: { href: string }) {
  const { t } = useTranslation();

  useEffect(() => {
    window.history.replaceState({}, '', href);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, [href]);

  return <p role="status">{t('budget.monthly.loading')}</p>;
}
