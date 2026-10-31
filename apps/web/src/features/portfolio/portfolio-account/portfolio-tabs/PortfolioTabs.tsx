import type { ReactNode } from 'react';
import type { AccountKind } from '@cadran/api-client';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './PortfolioTabs.module.css';

export type PortfolioTab =
  'positions' | 'operations' | 'valuations' | 'cash' | 'performance' | 'account' | 'contract';

/** Shared account navigation first, followed by investment-specific views. */
const tabs: { id: PortfolioTab; label: string }[] = [
  { id: 'account', label: 'Compte' },
  { id: 'operations', label: 'Opérations' },
  { id: 'valuations', label: 'Valorisations' },
  { id: 'positions', label: 'Positions' },
  { id: 'performance', label: 'Performance' },
];

const insuranceLabels: Partial<Record<PortfolioTab, string>> = {
  positions: 'Supports',
  cash: 'Liquidités',
};

/**
 * Navigation synced to `?tab=`. Insurance keeps its contract settings after the shared views.
 */
export function PortfolioTabs({
  accountId,
  active,
  asOf,
  kind,
  tools,
}: {
  accountId: string;
  active: PortfolioTab;
  asOf?: string;
  kind?: AccountKind;
  tools?: ReactNode;
}) {
  const accountTabs =
    kind === 'INSURANCE_CONTRACT'
      ? [
          ...tabs.map((tab) => ({ ...tab, label: insuranceLabels[tab.id] ?? tab.label })),
          { id: 'contract' as const, label: 'Contrat' },
        ]
      : tabs;
  return (
    <div className={styles.bar}>
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
      {tools ? <div className={styles.tools}>{tools}</div> : null}
    </div>
  );
}
