import { Icon } from '@/components/ui/icon/Icon';
import type { LifeInsuranceFeeTerms } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount, formatDecimal, formatCalendarNumericDay } from '@/lib/decimal';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { ContractSection } from '@/features/portfolio/life-insurance/contract-section/ContractSection';
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
  const latest = items.reduce<string | null>(
    (date, item) => (date === null || item.validFrom > date ? item.validFrom : date),
    null,
  );
  return (
    <ContractSection
      title="Barèmes de frais"
      label="Barèmes de frais"
      hint={
        loading ? (
          <SkeletonBlock className={styles.hintSkeleton} />
        ) : latest ? (
          `dernier barème du ${formatCalendarNumericDay(latest)}`
        ) : (
          'Aucun barème'
        )
      }
      action={
        <button
          className="secondary-action"
          type="button"
          disabled={loading || !writable}
          onClick={onAdd}
        >
          Ajouter un barème
        </button>
      }
    >
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
                      className="icon-ghost"
                      type="button"
                      disabled={!writable}
                      aria-label={`Supprimer le barème du ${formatCalendarNumericDay(item.validFrom)}`}
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
      {!loading && items.length === 0 ? <p>Aucun barème de frais renseigné.</p> : null}
    </ContractSection>
  );
}
