import type { AccountYieldReading } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './YieldReading.module.css';

interface YieldReadingProps {
  reading: AccountYieldReading;
}

/**
 * The three yield readings a rate screen must keep apart.
 *
 * Contractual or regulated rate, projection assumption, observed performance.
 * An assumption is never stored as a rate on a market product. Observed
 * performance is not computed from a pair of valuations.
 */
export function YieldReading({ reading }: YieldReadingProps) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="account-yield-reading" className={styles.section}>
      <h3 id="account-yield-reading">{t('accounts.rules.yield.title')}</h3>
      <p className={styles.hint}>{t('accounts.rules.yield.hint')}</p>
      <dl className={styles.readings}>
        <div>
          <dt>{t('accounts.rules.yield.contractual')}</dt>
          <dd>
            {reading.contractual === null
              ? t('accounts.rules.yield.noContractual')
              : t(
                  reading.contractual.guaranteed
                    ? 'accounts.rules.guaranteed'
                    : 'accounts.rules.revisable',
                )}
          </dd>
        </div>
        <div>
          <dt>{t('accounts.rules.yield.assumption')}</dt>
          <dd>{t('accounts.rules.yield.assumptionEmpty')}</dd>
        </div>
        <div>
          <dt>{t('accounts.rules.yield.observed')}</dt>
          <dd>{t('accounts.rules.yield.observedEmpty')}</dd>
        </div>
      </dl>
    </section>
  );
}
