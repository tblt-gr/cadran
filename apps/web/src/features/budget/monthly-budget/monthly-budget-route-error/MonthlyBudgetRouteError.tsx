import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from '@/features/budget/monthly-budget/MonthlyBudgetPage.module.css';

export function MonthlyBudgetRouteError({
  kind,
  href,
}: {
  kind: 'future' | 'invalid';
  href: string;
}) {
  const { t } = useTranslation();
  return (
    <section className={`card ${styles.routeError}`} role="alert">
      <h2>
        {t(
          kind === 'future'
            ? 'budget.monthly.route.futureTitle'
            : 'budget.monthly.route.invalidTitle',
        )}
      </h2>
      <p>
        {t(
          kind === 'future'
            ? 'budget.monthly.route.futureDescription'
            : 'budget.monthly.route.invalidDescription',
        )}
      </p>
      <a
        className="secondary-action"
        href={href}
        onClick={(event) => handleClientNavigation(event, href)}
      >
        {t('budget.monthly.route.recover')}
      </a>
    </section>
  );
}
