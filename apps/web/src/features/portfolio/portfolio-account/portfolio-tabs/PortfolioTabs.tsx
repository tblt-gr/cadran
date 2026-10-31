import type { AccountKind } from '@cadran/api-client';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './PortfolioTabs.module.css';

export type PortfolioTab =
  'positions' | 'operations' | 'valuations' | 'cash' | 'performance' | 'account' | 'contract';

/** Ordered by how often they are used: what is held, what happened, then the derived readings. */
const tabs: { id: PortfolioTab; label: string }[] = [
  { id: 'positions', label: 'Positions' },
  { id: 'operations', label: 'Opérations' },
  { id: 'valuations', label: 'Valorisations' },
  { id: 'cash', label: 'Espèces' },
  { id: 'performance', label: 'Performance' },
];

const insuranceLabels: Partial<Record<PortfolioTab, string>> = {
  positions: 'Supports',
  cash: 'Liquidités',
};

/**
 * Tab list synced to `?tab=`, ending with the account itself: its bank movements and rules, under
 * Contrat for a life insurance, which also renames two tabs.
 */
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
          ...tabs.map((tab) => ({ ...tab, label: insuranceLabels[tab.id] ?? tab.label })),
          { id: 'contract' as const, label: 'Contrat' },
        ]
      : [...tabs, { id: 'account' as const, label: 'Compte' }];
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
