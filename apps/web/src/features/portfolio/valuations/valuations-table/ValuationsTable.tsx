import type { DecimalAmount, PortfolioValuationSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import {
  detailLabel,
  flagLabel,
  summaryQualityLabel,
} from '@/features/portfolio/valuations/valuationLabels';
import { VALUATION_COLUMNS } from './valuationColumns';
import styles from './ValuationsTable.module.css';

interface Props {
  items: PortfolioValuationSummary[];
  onDetail: (valuation: PortfolioValuationSummary) => void;
  onReplace: (valuation: PortfolioValuationSummary) => void;
  onWithdraw: (valuation: PortfolioValuationSummary) => void;
}

/** Active valuations, newest first; a figure the server could not compute reads Non calculable. */
export function ValuationsTable({ items, onDetail, onReplace, onWithdraw }: Props) {
  const { i18n } = useTranslation();
  const money = (amount: DecimalAmount | null) =>
    amount ? (
      <MoneyValue value={formatAmount(amount.value, amount.assetCode, i18n.language)} />
    ) : (
      <EmptyValue label="Non calculable" />
    );

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">Valorisations du portefeuille</caption>
          <thead>
            <tr>
              {VALUATION_COLUMNS.map((text) => (
                <th key={text} scope="col">
                  {text}
                </th>
              ))}
              <th scope="col">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {items.map((valuation) => {
              const date = formatCalendarNumericDay(valuation.valuedOn);
              const quality = summaryQualityLabel[valuation.priceQuality];
              return (
                <tr key={valuation.id}>
                  <th scope="row">{date}</th>
                  <td>{detailLabel[valuation.detail]}</td>
                  <td className={styles.number}>{money(valuation.totalValue)}</td>
                  <td className={styles.number}>{money(valuation.cash)}</td>
                  <td className={styles.number}>{money(valuation.securitiesValue)}</td>
                  <td>
                    {valuation.completeness === 'COMPLETE' ? (
                      <StatusBadge tone="positive">Complète</StatusBadge>
                    ) : (
                      <StatusBadge tone="warning">Partielle</StatusBadge>
                    )}
                  </td>
                  <td>{quality ?? <EmptyValue label="Non détaillé" />}</td>
                  <td>
                    <span className={styles.flags}>
                      {valuation.flags.map((flag) => (
                        <StatusBadge key={flag} tone="warning">
                          {flagLabel[flag]}
                        </StatusBadge>
                      ))}
                    </span>
                  </td>
                  <td>
                    <ActionMenu
                      label={`Actions pour la valorisation du ${date}`}
                      items={[
                        {
                          id: 'detail',
                          icon: 'info',
                          label: `Détail de la valorisation du ${date}`,
                          text: 'Détail',
                          onSelect: () => onDetail(valuation),
                        },
                        {
                          id: 'replace',
                          icon: 'edit',
                          label: `Remplacer la valorisation du ${date}`,
                          text: 'Remplacer',
                          onSelect: () => onReplace(valuation),
                        },
                        {
                          id: 'withdraw',
                          icon: 'archive',
                          label: `Retirer la valorisation du ${date}`,
                          text: 'Retirer',
                          onSelect: () => onWithdraw(valuation),
                        },
                      ]}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
