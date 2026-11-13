import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { Instrument } from '@cadran/api-client';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import styles from './InstrumentTable.module.css';

const typeLabels: Record<string, string> = {
  EQUITY: 'Action',
  ETF: 'ETF',
  FUND: 'Fonds',
  BOND: 'Obligation',
  OTHER: 'Autre',
};
const classLabels: Record<string, string> = {
  EQUITY: 'Actions',
  BOND: 'Obligations',
  MONEY_MARKET: 'Monétaire',
  REAL_ESTATE: 'Immobilier',
  COMMODITY: 'Matières premières',
  MIXED: 'Diversifié',
  OTHER: 'Autre',
};
const regionLabels: Record<string, string> = {
  FRANCE: 'France',
  EUROPE: 'Europe',
  NORTH_AMERICA: 'Amérique du Nord',
  ASIA_PACIFIC: 'Asie-Pacifique',
  EMERGING: 'Pays émergents',
  WORLD: 'Monde',
  OTHER: 'Autre',
};
interface Props {
  items: Instrument[];
  onEdit: (item: Instrument) => void;
  onStatus: (item: Instrument) => void;
}
export function InstrumentTable({ items, onEdit, onStatus }: Props) {
  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">Instruments</caption>
          <thead>
            <tr>
              {['Nom', 'Type', 'Région', 'Statut', ''].map((text) => (
                <th key={text} scope="col">
                  {text}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <th scope="row">
                  <InstrumentLink instrument={item} />
                  <small>
                    {[item.isin, item.ticker].filter(Boolean).join(' · ') || 'Aucun identifiant'}
                  </small>
                </th>
                <td>
                  {typeLabels[item.instrumentType]}
                  <small className={styles.sub}>
                    {item.assetClass ? classLabels[item.assetClass] : 'Classe non renseignée'}
                  </small>
                </td>
                <td>
                  {item.region ? regionLabels[item.region] : 'Non renseignée'}
                  <small className={styles.sub}>{item.currencyCode}</small>
                </td>
                <td>
                  <StatusBadge tone={item.status === 'ACTIVE' ? 'positive' : 'info'}>
                    {item.status === 'ACTIVE' ? 'Actif' : 'Archivé'}
                  </StatusBadge>
                </td>
                <td>
                  <ActionMenu
                    label={`Actions pour ${item.name}`}
                    items={[
                      {
                        id: 'edit',
                        icon: 'edit',
                        label: `Modifier ${item.name}`,
                        text: 'Modifier',
                        onSelect: () => onEdit(item),
                      },
                      {
                        id: 'status',
                        icon: 'archive',
                        label: `${item.status === 'ACTIVE' ? 'Archiver' : 'Réactiver'} ${item.name}`,
                        text: item.status === 'ACTIVE' ? 'Archiver' : 'Réactiver',
                        onSelect: () => onStatus(item),
                      },
                    ]}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
