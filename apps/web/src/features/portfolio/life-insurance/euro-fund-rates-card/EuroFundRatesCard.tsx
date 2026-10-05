import type { LifeInsuranceEuroFundRate, LifeInsuranceEuroFund } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { formatDecimal } from '@/lib/decimal';
import { basisLabels } from '@/features/portfolio/life-insurance/lifeInsuranceLabels';
import styles from './EuroFundRatesCard.module.css';
export function EuroFundRatesCard({
  items = [],
  euroFunds = [],
  lastClosedYear,
  loading = false,
  writable = true,
  onAdd,
  onRemove,
}: {
  items?: LifeInsuranceEuroFundRate[];
  euroFunds?: LifeInsuranceEuroFund[];
  lastClosedYear: number;
  loading?: boolean;
  writable?: boolean;
  onAdd: () => void;
  onRemove: (id: string) => void;
}) {
  return (
    <section className={`card ${styles.card}`} aria-label="Taux des fonds en euros">
      <header className={styles.heading}>
        <h3>Taux des fonds en euros</h3>
        <button
          className="secondary-action"
          type="button"
          disabled={loading || !writable}
          onClick={onAdd}
        >
          Renseigner un taux
        </button>
      </header>
      <div className={styles.warnings}>
        {euroFunds
          .filter((f) => f.missingLastYear)
          .map((f) => (
            <p key={f.instrument.id}>
              {f.instrument.name} :{' '}
              <StatusBadge tone="warning">{`Taux ${lastClosedYear} non renseigné`}</StatusBadge>
            </p>
          ))}
      </div>
      <div
        className={styles.scroll}
        role="region"
        aria-label="Taux des fonds en euros"
        tabIndex={0}
      >
        <table className={styles.table}>
          <caption>Taux annoncés par l'assureur, distincts de la performance du contrat.</caption>
          <thead>
            <tr>
              {['Année', 'Support', 'Taux servi', 'Bonus', 'Base', 'Source', 'Actions'].map((h) => (
                <th key={h} scope="col">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          {loading ? (
            <SkeletonTableRows columns={7} />
          ) : (
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{item.year}</td>
                  <td>
                    {euroFunds.find((f) => f.instrument.id === item.instrumentId)?.instrument
                      .name ?? 'Support indisponible'}
                  </td>
                  <td>{formatDecimal(item.servedRate, 'fr-FR')} %</td>
                  <td>
                    {item.bonusRate === null ? (
                      <EmptyValue label="Non renseigné" />
                    ) : (
                      `${formatDecimal(item.bonusRate, 'fr-FR')} %`
                    )}
                  </td>
                  <td>{basisLabels[item.basis]}</td>
                  <td>{item.sourceNote ?? <EmptyValue label="Non renseigné" />}</td>
                  <td>
                    <button
                      className="secondary-action"
                      type="button"
                      disabled={!writable}
                      aria-label={`Supprimer le taux ${item.year} de ${euroFunds.find((f) => f.instrument.id === item.instrumentId)?.instrument.name ?? 'ce support'}`}
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
      {!loading && items.length === 0 ? <p>Aucun taux renseigné.</p> : null}
    </section>
  );
}
