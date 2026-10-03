import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { OperationsState } from './OperationsState';

export function NotPortfolioState({ accountId }: { accountId: string }) {
  const href = `/accounts/${accountId}`;
  return (
    <OperationsState title="Ce compte n’est pas suivi en mode portefeuille">
      <a
        className="secondary-action"
        href={href}
        onClick={(event) => handleClientNavigation(event, href)}
      >
        Ouvrir le compte
      </a>
    </OperationsState>
  );
}
