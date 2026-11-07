import type { HoldingsAccountSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { holdingsInstrumentLine, holdingsMoney } from '@/features/investments/holdingsFormat';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { formatDecimal } from '@/lib/decimal';
import styles from './HoldingsByAccountTable.module.css';

/** One sub-row per line of the account's retained valuation, under the account row. */
export function AccountLinesRows({
  account,
  assetCode,
}: {
  account: HoldingsAccountSummary;
  assetCode: string | null;
}) {
  const { t } = useTranslation();

  return account.lines.map((line) => (
    <tr className={styles.lineRow} key={line.instrument.id}>
      <th scope="row">
        <strong className={styles.name}>{line.instrument.name}</strong>
        <span className={styles.identifier}>
          {holdingsInstrumentLine(line.instrument, line.instrumentType)}
        </span>
      </th>
      <td className={styles.number} data-label={t('investments.table.quantity')}>
        {formatDecimal(line.quantity, 'fr')}
      </td>
      <td className={styles.number} data-label={t('investments.byAccount.securities')}>
        {line.value !== null
          ? holdingsMoney(line.value, assetCode)
          : t(
              assetCode === null
                ? 'investments.summary.mixedAssets'
                : 'investments.table.missingPrice',
            )}
      </td>
      <td className={styles.number} colSpan={3} data-label={t('investments.byAccount.gain')}>
        {line.unrealizedGain !== null ? (
          <UnrealizedGain
            assetCode={assetCode ?? ''}
            performance={line.performance}
            value={line.unrealizedGain}
          />
        ) : line.value !== null ? (
          t('investments.table.unknownCost')
        ) : (
          <EmptyValue label={t('investments.table.gainNotCalculable')} />
        )}
      </td>
    </tr>
  ));
}
