import type { LifeInsuranceFeeTerms } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { formatAmount, formatDecimal, formatCalendarNumericDay } from '@/lib/decimal';
import styles from './FeeTermsCard.module.css';
export function FeeTermsCard({
  items = [],
  loading = false,
  writable = true,
  onAdd,
  onRemove,
}: {
  items?: LifeInsuranceFeeTerms[];
  loading?: boolean;
  writable?: boolean;
  onAdd: () => void;
  onRemove: (id: string) => void;
}) {
  const percent = (value: string | null) =>
    value === null ? <EmptyValue label="Non renseigné" /> : `${formatDecimal(value, 'fr-FR')} %`;
  return (
    <section className={`card ${styles.card}`} aria-label="Barèmes de frais">
      <header className={styles.heading}>
        <h3>Barèmes de frais</h3>
        <button
          className="secondary-action"
          type="button"
          disabled={loading || !writable}
          onClick={onAdd}
        >
          Ajouter un barème
        </button>
      </header>
      <div className={styles.scroll} role="region" aria-label="Barèmes de frais" tabIndex={0}>
        <table className={styles.table}>
          <thead>
            <tr>
              {[
                'À partir du',
                'Versement',
                'Gestion fonds euros',
                'Gestion UC',
                'Arbitrage',
                'Arbitrage fixe',
                'Source',
                'Actions',
              ].map((h) => (
                <th scope="col" key={h}>
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          {loading ? (
            <SkeletonTableRows columns={8} />
          ) : (
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{formatCalendarNumericDay(item.validFrom)}</td>
                  <td>{percent(item.entryFeeRate)}</td>
                  <td>{percent(item.euroFundManagementFeeRate)}</td>
                  <td>{percent(item.unitLinkedManagementFeeRate)}</td>
                  <td>{percent(item.switchFeeRate)}</td>
                  <td>
                    {item.switchFeeFixed ? (
                      formatAmount(
                        item.switchFeeFixed.value,
                        item.switchFeeFixed.assetCode,
                        'fr-FR',
                      )
                    ) : (
                      <EmptyValue label="Non renseigné" />
                    )}
                  </td>
                  <td>{item.sourceNote ?? <EmptyValue label="Non renseigné" />}</td>
                  <td>
                    <button
                      className="secondary-action"
                      type="button"
                      disabled={!writable}
                      aria-label={`Supprimer le barème du ${formatCalendarNumericDay(item.validFrom)}`}
                      onClick={() => onRemove(item.id)}
                    >
                      Supprimer
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
      {!loading && items.length === 0 ? <p>Aucun barème de frais renseigné.</p> : null}
    </section>
  );
}
