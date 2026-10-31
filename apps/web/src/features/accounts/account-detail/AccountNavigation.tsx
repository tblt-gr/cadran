import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from '@/features/portfolio/portfolio-account/portfolio-tabs/PortfolioTabs.module.css';

export function accountOperationsActive() {
  const params = new URLSearchParams(window.location.search);
  return params.get('tab') === 'operations' || params.has('month');
}

export function AccountNavigation({
  accountId,
  operations,
}: {
  accountId: string;
  operations: boolean;
}) {
  return (
    <div className={styles.bar}>
      <nav aria-label="Sections du compte" className={styles.tabs}>
        {[
          { id: 'account', label: 'Compte' },
          { id: 'operations', label: 'Opérations' },
        ].map((tab) => {
          const href = `/accounts/${accountId}?tab=${tab.id}`;
          return (
            <a
              key={tab.id}
              href={href}
              aria-current={(operations ? 'operations' : 'account') === tab.id ? 'page' : undefined}
              onClick={(event) => handleClientNavigation(event, href)}
            >
              {tab.label}
            </a>
          );
        })}
      </nav>
    </div>
  );
}
