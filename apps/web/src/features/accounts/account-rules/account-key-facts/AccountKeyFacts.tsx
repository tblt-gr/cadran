import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './AccountKeyFacts.module.css';

/**
 * What the account itself states about its life: when it opened and, once it
 * is closed, when. Read from the account the reader already holds; a closing
 * date a product rule would impose (a youth savings plan ending at a given
 * age) is not an account field and is not worked out here.
 */
export function AccountKeyFacts({ account }: { account: Account }) {
  const { i18n, t } = useTranslation();

  return (
    <dl className={styles.facts}>
      <div>
        <dt>{t('accounts.rules.facts.opened')}</dt>
        <dd>{formatCalendarDay(account.openedOn, i18n.language)}</dd>
      </div>
      {account.closedOn === null ? null : (
        <div>
          <dt>{t('accounts.rules.facts.closed')}</dt>
          <dd>{formatCalendarDay(account.closedOn, i18n.language)}</dd>
        </div>
      )}
    </dl>
  );
}
