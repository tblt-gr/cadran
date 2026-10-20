import { useTranslation } from 'react-i18next';
import styles from './AccountSheet.module.css';

/**
 * The two yield readings a rate screen must keep apart from the contractual
 * rate: a projection assumption is never stored as a rate on a market product,
 * and observed performance is not computed from a pair of valuations.
 */
export function YieldDetails() {
  const { t } = useTranslation();

  return (
    <dl className={styles.readings}>
      <div>
        <dt>{t('accounts.rules.yield.assumption')}</dt>
        <dd>{t('accounts.rules.yield.assumptionEmpty')}</dd>
      </div>
      <div>
        <dt>{t('accounts.rules.yield.observed')}</dt>
        <dd>{t('accounts.rules.yield.observedEmpty')}</dd>
      </div>
    </dl>
  );
}
