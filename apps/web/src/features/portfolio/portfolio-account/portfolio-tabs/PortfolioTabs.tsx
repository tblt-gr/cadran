import type { ReactNode } from 'react';
import type { AccountKind } from '@cadran/api-client';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';
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
      <div className={styles.nav}>
        <TabsNav
          items={accountTabs.map((tab) => ({
            id: tab.id,
            label: tab.label,
            active: tab.id === active,
            href: `/portfolios/${accountId}?tab=${tab.id}${asOf ? `&asOf=${asOf}` : ''}`,
          }))}
          label="Sections du portefeuille"
        />
      </div>
      {tools ? <div className={styles.tools}>{tools}</div> : null}
    </div>
  );
}
