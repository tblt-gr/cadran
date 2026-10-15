import type { NetWorthContribution } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './MissingValuationList.module.css';

interface MissingValuationListProps {
  missing: NetWorthContribution[];
  onNavigate: () => void;
  onRecord: (contribution: NetWorthContribution) => void;
}

/**
 * One row per account without a valuation on the requested day. A portfolio
 * is valued from its positions, not from a typed balance, so it only offers
 * its own page.
 */
export function MissingValuationList({ missing, onNavigate, onRecord }: MissingValuationListProps) {
  const { t } = useTranslation();

  if (missing.length === 0) {
    return <p className={styles.empty}>{t('dashboard.missingValuations.empty')}</p>;
  }

  return (
    <>
      <p className={styles.hint}>{t('dashboard.missingValuations.hint')}</p>
      <ul className={styles.list}>
        {missing.map((contribution) => {
          const portfolio = contribution.kind === 'PORTFOLIO';
          const href = portfolio
            ? `/portfolios/${contribution.accountId}`
            : `/accounts/${contribution.accountId}`;

          return (
            <li className={styles.row} key={contribution.accountId}>
              <div className={styles.identity}>
                <span className={styles.label}>{contribution.label}</span>
                <span className={styles.kind}>{t(`accounts.kinds.${contribution.kind}`)}</span>
              </div>
              <div className={styles.actions}>
                {portfolio ? null : (
                  <button
                    aria-label={t('dashboard.missingValuations.recordOf', {
                      label: contribution.label,
                    })}
                    className="primary-action"
                    onClick={() => onRecord(contribution)}
                    type="button"
                  >
                    <Icon name="balance" size={16} />
                    {t('dashboard.missingValuations.record')}
                  </button>
                )}
                <a
                  aria-label={t(
                    portfolio
                      ? 'dashboard.missingValuations.openPortfolioOf'
                      : 'dashboard.missingValuations.openAccountOf',
                    { label: contribution.label },
                  )}
                  className="secondary-action"
                  href={href}
                  onClick={(event) => {
                    if (handleClientNavigation(event, href)) onNavigate();
                  }}
                >
                  {t(
                    portfolio
                      ? 'dashboard.missingValuations.openPortfolio'
                      : 'dashboard.missingValuations.openAccount',
                  )}
                </a>
              </div>
            </li>
          );
        })}
      </ul>
    </>
  );
}
