import type { LifeInsuranceContract } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { managementLabels } from '@/features/portfolio/life-insurance/lifeInsuranceLabels';
import styles from './ContractCard.module.css';
export function ContractCard({
  contract,
  onEdit,
  writable = true,
}: {
  contract?: LifeInsuranceContract;
  onEdit: () => void;
  writable?: boolean;
}) {
  const fields = [
    ['Assureur', contract?.insurer],
    ['Distributeur', contract?.distributor],
    ['Nom commercial', contract?.commercialName],
    ['Numéro de contrat', contract?.contractNumberMasked],
    ['Souscrit le', contract ? formatCalendarNumericDay(contract.subscribedOn) : null],
    ['Date fiscale du contrat', contract ? formatCalendarNumericDay(contract.taxStartOn) : null],
    ['Mode de gestion', contract ? managementLabels[contract.managementMode] : null],
  ];
  return (
    <section className={`card ${styles.card}`} aria-label="Informations du contrat">
      <header className={styles.heading}>
        <h3>Contrat</h3>
        <button
          className="secondary-action"
          type="button"
          disabled={!contract || !writable}
          onClick={onEdit}
        >
          Modifier
        </button>
      </header>
      <dl className={styles.details}>
        {fields.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>
              {!contract ? (
                <SkeletonBlock className={styles.valueSkeleton} />
              ) : (
                (value ?? <EmptyValue label="Non renseigné" />)
              )}
            </dd>
          </div>
        ))}
        <div>
          <dt>Statut</dt>
          <dd>
            {contract ? (
              <StatusBadge tone={contract.status === 'OPEN' ? 'positive' : 'neutral'}>
                {contract.status === 'OPEN' ? 'Ouvert' : 'Clos'}
              </StatusBadge>
            ) : (
              <SkeletonBlock className={styles.valueSkeleton} />
            )}
            {contract?.closedOn ? ` le ${formatCalendarNumericDay(contract.closedOn)}` : null}
          </dd>
        </div>
      </dl>
    </section>
  );
}
