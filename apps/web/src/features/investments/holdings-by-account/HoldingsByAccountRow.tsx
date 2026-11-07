import type { HoldingsAccountSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Icon } from '@/components/ui/icon/Icon';
import { holdingsDay, holdingsMoney, holdingsPercent } from '@/features/investments/holdingsFormat';
import { UnrealizedGain } from '@/features/portfolio/unrealized-gain/UnrealizedGain';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { AccountLinesRows } from './AccountLinesRows';
import styles from './HoldingsByAccountTable.module.css';

/** One account: its securities, gain and share, and the detail its valuation allows. */
export function HoldingsByAccountRow({
  account,
  assetCode,
  open,
  onToggle,
}: {
  account: HoldingsAccountSummary;
  assetCode: string | null;
  open: boolean;
  onToggle: () => void;
}) {
  const { t } = useTranslation();
  const accountHref = `/portfolios/${account.accountId}`;
  const valuationsHref = `${accountHref}?tab=valuations`;
  const detailed = account.detail === 'LINES';
  const dash = (label: string) => <EmptyValue label={label} />;
  const notCalculable = t('investments.table.notCalculable');

  return (
    <>
      <tr className={account.securitiesValue === null ? styles.greyed : undefined}>
        <th scope="row">
          <a
            className={styles.account}
            href={accountHref}
            onClick={(event) => handleClientNavigation(event, accountHref)}
          >
            {account.label}
          </a>
          {detailed ? null : (
            <>
              <span className={styles.identifier}>
                {t(`investments.byAccount.${account.detail as 'TOTAL' | 'PARTIAL' | 'NONE'}`)}
              </span>
              <a
                className={styles.identifier}
                href={valuationsHref}
                onClick={(event) => handleClientNavigation(event, valuationsHref)}
              >
                {t('investments.unsplit.action')}
                <span className="sr-only"> {account.label}</span>
              </a>
            </>
          )}
        </th>
        <td className={styles.number} data-label={t('investments.byAccount.securities')}>
          {account.securitiesValue === null
            ? dash(notCalculable)
            : holdingsMoney(account.securitiesValue, assetCode)}
        </td>
        <td className={styles.number} data-label={t('investments.byAccount.gain')}>
          {account.unrealizedGain !== null ? (
            <UnrealizedGain
              assetCode={assetCode ?? ''}
              performance={account.performance}
              value={account.unrealizedGain}
            />
          ) : detailed && account.securitiesValue !== null ? (
            t('investments.table.unknownCost')
          ) : (
            dash(t('investments.table.gainNotCalculable'))
          )}
        </td>
        <td className={styles.number} data-label={t('investments.byAccount.share')}>
          {account.share.percentDisplay === null
            ? dash(notCalculable)
            : holdingsPercent(account.share.percentDisplay)}
        </td>
        <td className={styles.number} data-label={t('investments.byAccount.valuedOn')}>
          {account.valuedOn === null ? dash(notCalculable) : holdingsDay(account.valuedOn)}
        </td>
        <td className={styles.toggle}>
          {detailed ? (
            <button
              aria-expanded={open}
              aria-label={t('investments.byAccount.linesToggle', { name: account.label })}
              className={`icon-button ${styles.chevron}`}
              onClick={onToggle}
              type="button"
            >
              <Icon name="chevron-right" size={14} />
            </button>
          ) : null}
        </td>
      </tr>
      {detailed && open ? <AccountLinesRows account={account} assetCode={assetCode} /> : null}
    </>
  );
}
