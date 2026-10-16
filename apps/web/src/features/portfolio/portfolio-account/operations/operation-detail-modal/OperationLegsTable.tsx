import type { PortfolioOperationLeg } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount, formatDecimal, isZeroDecimal } from '@/lib/decimal';
import styles from './OperationDetailModal.module.css';

export function OperationLegsTable({
  legs,
  caption = 'Supports de l’opération',
}: {
  legs: PortfolioOperationLeg[];
  caption?: string;
}) {
  const { i18n } = useTranslation();
  return (
    <div className={styles.scroll} role="region" aria-label={caption} tabIndex={0}>
      <table>
        <caption>{caption}</caption>
        <thead>
          <tr>
            {['Sens', 'Support', 'Parts', 'Valeur liquidative', 'Montant', 'Écart d’arrondi'].map(
              (label) => (
                <th scope="col" key={label}>
                  {label}
                </th>
              ),
            )}
          </tr>
        </thead>
        <tbody>
          {legs.map((leg) => (
            <tr key={leg.index}>
              <td>{leg.direction === 'IN' ? 'Entrée' : 'Sortie'}</td>
              <th scope="row">
                {leg.instrument?.name ?? <EmptyValue label="Support indisponible" />}
              </th>
              <td>{formatDecimal(leg.quantity, i18n.language)}</td>
              <td>{formatDecimal(leg.unitPrice, i18n.language)}</td>
              <td>{formatAmount(leg.amount.value, leg.amount.assetCode, i18n.language)}</td>
              <td>
                {leg.roundingResidual && !isZeroDecimal(leg.roundingResidual.value) ? (
                  formatAmount(
                    leg.roundingResidual.value,
                    leg.roundingResidual.assetCode,
                    i18n.language,
                  )
                ) : (
                  <EmptyValue label="Non renseigné" />
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {legs.length === 0 ? <p>Aucun support.</p> : null}
    </div>
  );
}
