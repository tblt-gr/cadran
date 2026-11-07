import type { Holding, Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { formatDecimal } from '@/lib/decimal';
import { holdingsDay, holdingsMoney } from '@/features/investments/holdingsFormat';
import styles from './HoldingsTable.module.css';

/** One sub-row per account holding the instrument, under the aggregated row. */
export function HoldingAccountsRows({
  holding,
  assetCode,
}: {
  holding: Holding;
  assetCode: Holdings['assetCode'];
}) {
  const { t } = useTranslation();

  return holding.accounts.map((account) => {
    const href = `/portfolios/${account.accountId}?tab=positions`;
    return (
      <tr className={styles.accountRow} key={account.accountId}>
        <th scope="row">
          <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
            {account.label}
          </a>
        </th>
        <td data-label={t('investments.table.quantity')}>
          {formatDecimal(account.quantity, 'fr')}
        </td>
        <td colSpan={2} data-label={t('investments.table.lastPrice')}>
          {t('investments.table.valuedOn', { date: holdingsDay(account.valuedOn) })}
        </td>
        <td data-label={t('investments.table.value')}>
          {account.value === null ? (
            <EmptyValue label={t('investments.table.notCalculable')} />
          ) : (
            holdingsMoney(account.value, assetCode)
          )}
        </td>
        <td data-label={t('investments.table.gain')}>
          {account.unrealizedGain === null ? (
            <EmptyValue label={t('investments.table.gainNotCalculable')} />
          ) : (
            <UnrealizedGain
              assetCode={assetCode ?? ''}
              performance={account.performance}
              value={account.unrealizedGain}
            />
          )}
        </td>
      </tr>
    );
  });
}
