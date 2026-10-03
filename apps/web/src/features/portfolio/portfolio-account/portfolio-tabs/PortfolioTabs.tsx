import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './PortfolioTabs.module.css';

export type PortfolioTab = 'operations';

const tabs: { id: PortfolioTab; label: string }[] = [{ id: 'operations', label: 'Opérations' }];

/** Tab list synced to `?tab=`; later tickets add their own tabs to this list. */
export function PortfolioTabs({ accountId, active }: { accountId: string; active: PortfolioTab }) {
  return (
    <nav aria-label="Sections du portefeuille" className={styles.tabs}>
      {tabs.map((tab) => {
        const href = `/portfolios/${accountId}?tab=${tab.id}`;
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
