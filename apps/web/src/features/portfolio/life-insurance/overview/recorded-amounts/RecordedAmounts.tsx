import type { RefObject } from 'react';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import {
  money,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { CumulativeSources } from '@/features/portfolio/life-insurance/overview/recorded-amounts/cumulative-sources/CumulativeSources';
import styles from './RecordedAmounts.module.css';
export function RecordedAmounts({
  summary,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const cumulative = summary?.cumulative;
  const rows: [string, string | null][] = [
    ['Versements bruts', cumulative?.premiums ?? null],
    ['Rachats bruts', cumulative?.surrendersGross ?? null],
    ['Rachats nets versés', cumulative?.surrendersNetPaid ?? null],
    ['Frais d’entrée', cumulative?.fees.ENTRY ?? null],
    ['Frais de gestion', cumulative?.fees.MANAGEMENT ?? null],
    ['Frais d’arbitrage', cumulative?.fees.SWITCH ?? null],
    ['Frais de rachat', cumulative?.fees.SURRENDER ?? null],
    ['Autres frais', cumulative?.fees.OTHER ?? null],
    ['Impôt retenu', cumulative?.withheldTaxes.INCOME_TAX ?? null],
    ['Prélèvements sociaux', cumulative?.withheldTaxes.SOCIAL_CONTRIBUTIONS ?? null],
    ['Autres retenues', cumulative?.withheldTaxes.OTHER ?? null],
    ['Intérêts crédités bruts', cumulative?.interestCredited.gross ?? null],
    ['Intérêts crédités nets', cumulative?.interestCredited.net ?? null],
    ['Participation aux bénéfices brute', cumulative?.profitSharing.gross ?? null],
    ['Participation aux bénéfices nette', cumulative?.profitSharing.net ?? null],
  ];
  return (
    <section className={`card ${styles.card}`} aria-busy={!summary}>
      <h3>Montants cumulés enregistrés</h3>
      <p>
        Depuis la souscription jusqu’à la date sélectionnée. Montants observés, sans estimation ;
        les arbitrages sont exclus des versements et des rachats.
      </p>
      <div className={styles.scroll} role="region" aria-label="Montants cumulés" tabIndex={0}>
        <table>
          <caption className="sr-only">Montants cumulés enregistrés</caption>
          <thead>
            <tr>
              <th scope="col">Nature</th>
              <th scope="col">Montant</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(([label, value]) => (
              <tr key={label}>
                <th scope="row">{label}</th>
                <td className="money">
                  {summary ? (
                    money(value, summary.assetCode)
                  ) : (
                    <SkeletonBlock className={styles.amountSkeleton} />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <CumulativeSources
        summary={summary}
        accountId={accountId}
        sourceReturnFocus={sourceReturnFocus}
      />
    </section>
  );
}
