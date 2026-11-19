import type { ReactNode } from 'react';
import type { AccountKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { TabsNav } from '@/components/ui/tabs-nav/TabsNav';
import styles from './PortfolioTabs.module.css';

export type PortfolioTab =
  'positions' | 'operations' | 'valuations' | 'cash' | 'performance' | 'account' | 'contract';

/** Shared account navigation first, followed by investment-specific views. */
const tabIds: PortfolioTab[] = ['account', 'operations', 'valuations', 'positions', 'performance'];

function tabLabel(t: TFunction, id: PortfolioTab, kind?: AccountKind): string {
  if (kind === 'INSURANCE_CONTRACT' && (id === 'positions' || id === 'cash'))
    return t(`portfolio.account.tabs.insurance.${id}`);
  return t(`portfolio.account.tabs.${id}`);
}

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
  const { t } = useTranslation();
  const ids = kind === 'INSURANCE_CONTRACT' ? [...tabIds, 'contract' as const] : tabIds;
  const accountTabs = ids.map((id) => ({ id, label: tabLabel(t, id, kind) }));
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
          label={t('portfolio.account.tabs.label')}
        />
      </div>
      {tools ? <div className={styles.tools}>{tools}</div> : null}
    </div>
  );
}
