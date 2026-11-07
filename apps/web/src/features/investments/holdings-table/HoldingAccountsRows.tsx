import type { Holding, Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { NotComputable } from '@/features/investments/explain-info/NotComputable';
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
        <td className={styles.number} data-label={t('investments.table.quantity')}>
          {formatDecimal(account.quantity, 'fr')}
        </td>
        <td className={styles.number} colSpan={2} data-label={t('investments.table.lastPrice')}>
          {t('investments.table.valuedOn', { date: holdingsDay(account.valuedOn) })}
        </td>
        <td className={styles.number} data-label={t('investments.table.value')}>
          {account.value === null ? (
            <NotComputable
              figure={t('investments.notComputable.figure', {
                column: t('investments.table.value'),
                name: `${holding.instrument.name}, ${account.label}`,
              })}
              reason={t(
                assetCode === null
                  ? 'investments.summary.mixedAssets'
                  : 'investments.table.missingPrice',
              )}
            />
          ) : (
            holdingsMoney(account.value, assetCode)
          )}
        </td>
        <td className={styles.number} data-label={t('investments.table.gain')}>
          {account.unrealizedGain === null ? (
            <NotComputable
              figure={t('investments.notComputable.figure', {
                column: t('investments.table.gain'),
                name: `${holding.instrument.name}, ${account.label}`,
              })}
              reason={t(
                account.value === null
                  ? 'investments.table.gainNotCalculable'
                  : 'investments.table.unknownCost',
              )}
            />
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
