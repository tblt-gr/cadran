import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  money,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './TaxTrackingCard.module.css';
const columns = {
  year: 'Année',
  premiums: 'Versements',
  surrendersGross: 'Rachats bruts',
  surrendersNetPaid: 'Net versé',
  withheldIncomeTax: 'Impôt retenu',
  withheldSocialContributions: 'Prélèvements sociaux',
};
export function TaxTrackingCard({ summary }: { summary: Summary | null }) {
  const age = summary?.contractAge;
  return (
    <section
      className={`card ${styles.card}`}
      aria-busy={!summary}
      data-testid={!summary ? 'tax-tracking-skeleton' : undefined}
    >
      <h3>Suivi fiscal (informatif)</h3>
      {!summary ? (
        <SkeletonBlock className={styles.age} />
      ) : age ? (
        <p>{`Contrat ouvert depuis ${age.years} ans et ${age.months} mois (date fiscale du ${formatCalendarNumericDay(age.taxStartOn)}).`}</p>
      ) : (
        <p>Date fiscale indisponible.</p>
      )}
      <p>Aucune estimation d'impôt : aucun barème fiscal identifié pour ce contrat.</p>
      <div
        className={styles.scroll}
        role="region"
        aria-label="Montants fiscaux par année"
        tabIndex={0}
      >
        <table>
          <caption className="sr-only">Montants fiscaux enregistrés par année</caption>
          <thead>
            <tr>
              {Object.values(columns).map((label) => (
                <th key={label} scope="col">
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          {!summary ? (
            <SkeletonTableRows columns={6} />
          ) : (
            <tbody>
              {summary.taxYears.map((row) => (
                <tr key={row.year}>
                  <th scope="row">{row.year}</th>
                  {(Object.keys(columns) as (keyof typeof columns)[])
                    .filter((key) => key !== 'year')
                    .map((key) => (
                      <td className="money" key={key}>
                        {money(row[key] as string, summary.assetCode)}
                      </td>
                    ))}
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
      {summary?.taxYears.length === 0 ? <p>Aucune année enregistrée à cette date.</p> : null}
    </section>
  );
}
