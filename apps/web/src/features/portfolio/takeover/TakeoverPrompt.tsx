import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';

/** Overview card of an account that can still take over the positions it already holds. */
export function TakeoverPrompt({ onTakeover }: { onTakeover: () => void }) {
  return (
    <OperationsState title="Vos positions ne sont pas encore saisies">
      <p>Saisissez les lignes que ce compte détient déjà pour démarrer son suivi.</p>
      <button className="primary-action" onClick={onTakeover} type="button">
        Saisir mes positions
      </button>
    </OperationsState>
  );
}
