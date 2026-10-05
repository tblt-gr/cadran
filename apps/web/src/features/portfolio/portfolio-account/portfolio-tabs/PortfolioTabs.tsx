import type { AccountKind } from '@cadran/api-client';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './PortfolioTabs.module.css';

export type PortfolioTab =
  'performance' | 'cash' | 'positions' | 'operations' | 'valuations' | 'allocation' | 'contract';

const tabs: { id: PortfolioTab; label: string }[] = [
  { id: 'performance', label: 'Performance' },
  { id: 'cash', label: 'Espèces' },
  { id: 'positions', label: 'Positions' },
  { id: 'operations', label: 'Opérations' },
  { id: 'valuations', label: 'Valorisations' },
  { id: 'allocation', label: 'Répartition' },
];

/** Tab list synced to `?tab=`; later tickets add their own tabs to this list. */
export function PortfolioTabs({
  accountId,
  active,
  asOf,
  kind,
}: {
  accountId: string;
  active: PortfolioTab;
  asOf?: string;
  kind?: AccountKind;
}) {
  const accountTabs =
    kind === 'INSURANCE_CONTRACT'
      ? [
          ...tabs.map((tab) => ({
            ...tab,
            label:
              tab.id === 'positions' ? 'Supports' : tab.id === 'cash' ? 'Liquidités' : tab.label,
          })),
          { id: 'contract' as const, label: 'Contrat' },
        ]
      : tabs;
  return (
    <nav aria-label="Sections du portefeuille" className={styles.tabs}>
      {accountTabs.map((tab) => {
        const href = `/portfolios/${accountId}?tab=${tab.id}${asOf ? `&asOf=${asOf}` : ''}`;
        return (
          <a
            aria-current={tab.id === active ? 'page' : undefined}
            href={href}
            key={tab.id}
            onClick={(event) => handleClientNavigation(event, href)}
          >
            {tab.label}
          </a>
        );
      })}
    </nav>
  );
}
