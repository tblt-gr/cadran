import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { usePortfolios } from '@/features/portfolio/usePortfolioOverview';
import { PortfolioAccountCard } from './portfolio-account-card/PortfolioAccountCard';
import styles from './PortfoliosPage.module.css';
export function PortfoliosPage() {
  const portfolios = usePortfolios();
  const unavailable =
    portfolios.error instanceof OperationRequestError &&
    [401, 403].includes(portfolios.error.status);
  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <h2>Investissements</h2>
        <a
          className="secondary-action"
          href="/portfolios/instruments"
          onClick={(event) => handleClientNavigation(event, '/portfolios/instruments')}
        >
          Instruments
        </a>
      </header>
      {portfolios.isError ? (
        <section className="card" role="alert">
          <h3>
            {unavailable
              ? 'Investissements non accessibles'
              : 'Les investissements sont indisponibles'}
          </h3>
          <p>
            {unavailable
              ? 'Votre session ne permet pas d’accéder à cet espace.'
              : 'Le chargement a échoué. Réessayez.'}
          </p>
          <button
            className="secondary-action"
            type="button"
            onClick={() => void portfolios.refetch()}
          >
            Réessayer
          </button>
        </section>
      ) : portfolios.isPending ? (
        <>
          <p className="sr-only" role="status">
            Chargement des investissements…
          </p>
          <div className={styles.grid} aria-busy="true" data-testid="portfolios-skeleton">
            {[0, 1, 2].map((key) => (
              <PortfolioAccountCard account={null} key={key} />
            ))}
          </div>
        </>
      ) : portfolios.data.items.length === 0 ? (
        <section className="card">
          <p>
            Aucun compte d’investissement suivi. Créez un PEA ou un compte-titres en mode «
            Portefeuille ».
          </p>
          <a
            className="secondary-action"
            href="/accounts"
            onClick={(event) => handleClientNavigation(event, '/accounts')}
          >
            Créer un compte
          </a>
        </section>
      ) : (
        <>
          <div className={styles.grid}>
            {portfolios.data.items.map((account) => (
              <PortfolioAccountCard account={account} key={account.accountId} />
            ))}
          </div>
          {portfolios.data.truncated ? <p>Seuls les 50 premiers comptes sont affichés.</p> : null}
        </>
      )}
    </div>
  );
}
