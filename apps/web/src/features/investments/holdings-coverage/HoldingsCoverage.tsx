import type { HoldingsAccountSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './HoldingsCoverage.module.css';

/**
 * How many accounts carry a valuation at the date, and the ones that do not, each with the link
 * to its valuations. Nothing is shown while every account is valued.
 */
export function HoldingsCoverage({
  total,
  unvalued,
}: {
  total: number;
  unvalued: readonly HoldingsAccountSummary[];
}) {
  const { t } = useTranslation();
  if (unvalued.length === 0) return null;

  return (
    <section className={`card ${styles.coverage}`}>
      <p>
        {t('investments.coverage.summary', {
          count: total - unvalued.length,
          valued: total - unvalued.length,
          total,
        })}
      </p>
      <details>
        <summary>{t('investments.coverage.unvalued', { count: unvalued.length })}</summary>
        <ul className={styles.list}>
          {unvalued.map((account) => {
            const href = `/portfolios/${account.accountId}?tab=valuations`;
            return (
              <li key={account.accountId}>
                <span>{account.label}</span>
                <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
                  {t('investments.unsplit.action')}
                  <span className="sr-only"> {account.label}</span>
                </a>
              </li>
            );
          })}
        </ul>
      </details>
    </section>
  );
}
