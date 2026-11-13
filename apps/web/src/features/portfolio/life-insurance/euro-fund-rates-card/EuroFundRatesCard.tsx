import { Icon } from '@/components/ui/icon/Icon';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { LifeInsuranceEuroFundRate, LifeInsuranceEuroFund } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { ContractSection } from '@/features/portfolio/life-insurance/contract-section/ContractSection';
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
  const missing = euroFunds.some((fund) => fund.missingLastYear);
  const latestYear = items.reduce<number | null>(
    (year, item) => (year === null || item.year > year ? item.year : year),
    null,
  );
  return (
    <ContractSection
      title="Taux des fonds en euros"
      label="Taux des fonds en euros"
      hint={
        loading ? (
          <SkeletonBlock className={styles.hintSkeleton} />
        ) : missing ? (
          <StatusBadge tone="warning">{`Taux ${lastClosedYear} manquant`}</StatusBadge>
        ) : latestYear !== null ? (
          `dernier taux : ${latestYear}`
        ) : (
          'Aucun taux'
        )
      }
      action={
        <button
          className="secondary-action"
          type="button"
          disabled={loading || !writable}
          onClick={onAdd}
        >
          Renseigner un taux
        </button>
      }
    >
      <div className={styles.warnings}>
        {euroFunds
          .filter((f) => f.missingLastYear)
          .map((f) => (
            <p key={f.instrument.id}>
              <InstrumentLink instrument={f.instrument} /> :{' '}
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
                    {euroFunds.some((f) => f.instrument.id === item.instrumentId) ? (
                      <InstrumentLink
                        instrument={
                          euroFunds.find((f) => f.instrument.id === item.instrumentId)!.instrument
                        }
                      />
                    ) : (
                      'Support indisponible'
                    )}
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
                      className="icon-ghost"
                      type="button"
                      disabled={!writable}
                      aria-label={`Supprimer le taux ${item.year} de ${euroFunds.find((f) => f.instrument.id === item.instrumentId)?.instrument.name ?? 'ce support'}`}
                      onClick={() => onRemove(item.id)}
                    >
                      <Icon name="delete" size={16} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          )}
        </table>
      </div>
      {!loading && items.length === 0 ? <p>Aucun taux renseigné.</p> : null}
    </ContractSection>
  );
}
