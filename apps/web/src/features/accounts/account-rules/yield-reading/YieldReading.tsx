import type { AccountYieldReading } from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import styles from './YieldReading.module.css';

interface YieldReadingProps {
  reading: AccountYieldReading;
}

/**
 * The yield in one line: the contractual or regulated rate, guaranteed or
 * revisable, or that there is none on this date.
 *
 * The two other readings a rate screen must keep apart stay behind the details
 * button: a projection assumption is never stored as a rate on a market
 * product, and observed performance is not computed from a pair of valuations.
 */
export function YieldReading({ reading }: YieldReadingProps) {
  const { t } = useTranslation();
  const detailsId = useId();
  const [open, setOpen] = useState(false);

  return (
    <section aria-labelledby={`${detailsId}-title`} className={styles.section}>
      <header className={styles.heading}>
        <h3 id={`${detailsId}-title`}>{t('accounts.rules.yield.title')}</h3>
        <InfoButton
          aria-controls={detailsId}
          aria-expanded={open}
          label={t('accounts.rules.yield.detailsLabel')}
          onClick={() => setOpen((current) => !current)}
        />
      </header>
      <p className={styles.reading}>
        {reading.contractual === null
          ? t('accounts.rules.yield.noContractual')
          : t(
              reading.contractual.guaranteed
                ? 'accounts.rules.yield.guaranteed'
                : 'accounts.rules.yield.revisable',
            )}
      </p>
      {open ? (
        <dl className={styles.readings} id={detailsId}>
          <div>
            <dt>{t('accounts.rules.yield.assumption')}</dt>
            <dd>{t('accounts.rules.yield.assumptionEmpty')}</dd>
          </div>
          <div>
            <dt>{t('accounts.rules.yield.observed')}</dt>
            <dd>{t('accounts.rules.yield.observedEmpty')}</dd>
          </div>
        </dl>
      ) : null}
    </section>
  );
}
