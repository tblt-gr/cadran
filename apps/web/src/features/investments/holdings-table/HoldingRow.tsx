import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { Holding, Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { NotComputable } from '@/features/investments/explain-info/NotComputable';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Icon } from '@/components/ui/icon/Icon';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { formatDecimal } from '@/lib/decimal';
import {
  holdingsDay,
  holdingsInstrumentLine,
  holdingsMoney,
  holdingsPercent,
  holdingsUnitPrice,
} from '@/features/investments/holdingsFormat';
import { HoldingAccountsRows } from './HoldingAccountsRows';
import styles from './HoldingsTable.module.css';

/** The aggregated line of one instrument, with its per-account rows when disclosed. */
export function HoldingRow({
  holding,
  assetCode,
  open,
  onToggle,
}: {
  holding: Holding;
  assetCode: Holdings['assetCode'];
  open: boolean;
  onToggle: () => void;
}) {
  const { t } = useTranslation();
  const { instrument } = holding;
  const figureName = (column: string) =>
    t('investments.notComputable.figure', { column, name: instrument.name });
  const dash = (label: string) => <EmptyValue label={label} />;

  return (
    <>
      <tr>
        <th scope="row">
          <strong className={styles.name}>
            <InstrumentLink instrument={instrument} />
          </strong>
          <span className={styles.identifier}>
            {holdingsInstrumentLine(instrument, holding.instrumentType)}
          </span>
        </th>
        <td className={styles.number} data-label={t('investments.table.quantity')}>
          {formatDecimal(holding.quantity, 'fr')}
        </td>
        <td className={styles.number} data-label={t('investments.table.averageCost')}>
          {holding.averageUnitCost === null
            ? dash(t('investments.table.notCalculable'))
            : holdingsUnitPrice(holding.averageUnitCost, assetCode)}
        </td>
        <td className={styles.number} data-label={t('investments.table.lastPrice')}>
          {holding.lastPrice === null ? (
            dash(t('investments.table.notCalculable'))
          ) : (
            <>
              <span>{holdingsUnitPrice(holding.lastPrice.price, assetCode)}</span>
              <small className={styles.sub}>{holdingsDay(holding.lastPrice.priceDate)}</small>
            </>
          )}
        </td>
        <td className={styles.number} data-label={t('investments.table.value')}>
          {holding.value === null ? (
            <NotComputable
              figure={figureName(t('investments.table.value'))}
              reason={t(
                holding.reason === 'MIXED_ASSETS'
                  ? 'investments.summary.mixedAssets'
                  : 'investments.table.missingPrice',
              )}
            />
          ) : (
            holdingsMoney(holding.value, assetCode)
          )}
        </td>
        <td className={styles.number} data-label={t('investments.table.gain')}>
          {holding.unrealizedGain !== null ? (
            <UnrealizedGain
              assetCode={assetCode ?? ''}
              performance={holding.performance}
              value={holding.unrealizedGain}
            />
          ) : (
            <NotComputable
              figure={figureName(t('investments.table.gain'))}
              reason={t(
                holding.reason === 'UNKNOWN_LOT_COST'
                  ? 'investments.table.unknownCost'
                  : holding.reason === 'MIXED_ASSETS'
                    ? 'investments.summary.mixedAssets'
                    : 'investments.table.gainNotCalculable',
              )}
            />
          )}
        </td>
        <td className={styles.number} data-label={t('investments.table.share')}>
          {holding.share.percentDisplay === null
            ? dash(t('investments.table.notCalculable'))
            : holdingsPercent(holding.share.percentDisplay)}
        </td>
        <td className={styles.toggle}>
          <button
            aria-expanded={open}
            aria-label={t('investments.table.accountsToggle', { name: instrument.name })}
            className={`icon-ghost ${styles.chevron}`}
            onClick={onToggle}
            type="button"
          >
            <Icon name="chevron-right" size={14} />
          </button>
        </td>
      </tr>
      {open ? <HoldingAccountsRows assetCode={assetCode} holding={holding} /> : null}
    </>
  );
}
