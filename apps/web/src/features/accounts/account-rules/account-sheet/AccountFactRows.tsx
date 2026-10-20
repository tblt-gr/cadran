import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SheetRow } from '@/features/accounts/account-rules/sheet-row/SheetRow';
import { formatCalendarDay } from '@/lib/decimal';

/**
 * What the account itself states about its life: when it opened and, once it
 * is closed, when. Read from the account the reader already holds; a closing
 * date a product rule would impose (a youth savings plan ending at a given
 * age) is not an account field and is not worked out here.
 */
export function AccountFactRows({ account }: { account: Account }) {
  const { i18n, t } = useTranslation();

  return (
    <>
      <SheetRow
        label={t('accounts.rules.facts.opened')}
        value={formatCalendarDay(account.openedOn, i18n.language)}
      />
      {account.closedOn === null ? null : (
        <SheetRow
          label={t('accounts.rules.facts.closed')}
          value={formatCalendarDay(account.closedOn, i18n.language)}
        />
      )}
    </>
  );
}
