import type { InstrumentHolding } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { holdingsMoney } from '@/features/investments/holdingsFormat';
import { sheetGainTone } from '@/features/investments/instrument-sheet/sheetGainTone';
import { sheetReason } from '@/features/investments/instrument-sheet/sheetReason';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatDecimal } from '@/lib/decimal';
import styles from './InstrumentAccountsTable.module.css';

const columns = [
  'Compte',
  'Quantité',
  'Valeur',
  'Plus-value latente',
  'Réalisée',
  'Revenus',
  'Frais et taxes',
  'Opérations',
];
const gainClass = (value: string | null | undefined) => {
  const sign = sheetGainTone(value);
  return sign ? styles[sign] : '';
};
export function InstrumentAccountsTable({ data }: { data: InstrumentHolding | null }) {
  const accounts = data ? data.accounts : [null, null];
  return (
    <section className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption>Par compte</caption>
          <thead>
            <tr>
              {columns.map((label) => (
                <th key={label} scope="col">
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {accounts.map((account, index) => {
              const portfolio = account ? `/portfolios/${account.accountId}` : '';
              const operations = account
                ? `${portfolio}?tab=operations&instrument=${data!.instrument.id}`
                : '';
              const values = account
                ? [
                    account.quantity,
                    account.value,
                    account.unrealizedGain,
                    account.realizedGain,
                    account.income,
                    account.feesAndTaxes,
                  ]
                : [];
              return (
                <tr key={account?.accountId ?? index}>
                  <th scope="row" data-label="Compte">
                    <a
                      href={account ? portfolio : undefined}
                      aria-disabled={!account || undefined}
                      onClick={(event) => {
                        if (account) handleClientNavigation(event, portfolio);
                      }}
                    >
                      {account ? account.label : <SkeletonBlock className={styles.nameBlock} />}
                    </a>
                    {account?.reason ? (
                      <p className={styles.reason}>{sheetReason(account.reason)}</p>
                    ) : null}
                  </th>
                  {columns.slice(1, 7).map((label, column) => (
                    <td
                      key={label}
                      data-label={label}
                      className={`${styles.number} ${gainClass(column === 2 || column === 3 ? values[column] : null)}`.trim()}
                    >
                      {!account ? (
                        <SkeletonBlock className={styles.valueBlock} />
                      ) : values[column] === null ? (
                        <EmptyValue label={sheetReason(account.reason)} />
                      ) : column === 0 ? (
                        formatDecimal(values[column]!, 'fr')
                      ) : (
                        `${column >= 2 && !values[column]!.startsWith('-') && /[1-9]/.test(values[column]!) ? '+' : ''}${holdingsMoney(values[column]!, data!.instrument.currencyCode)}`
                      )}
                    </td>
                  ))}
                  <td data-label="Opérations">
                    <a
                      href={account ? operations : undefined}
                      aria-disabled={!account || undefined}
                      onClick={(event) => {
                        if (account) handleClientNavigation(event, operations);
                      }}
                    >
                      Voir les opérations
                    </a>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}
