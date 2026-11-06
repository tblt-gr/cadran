import type { PortfolioTakeoverBlockReason, PortfolioTakeoverError } from '@cadran/api-client';

type ResultLike = { error?: unknown; response?: Response };

/** A refused takeover request, classified by the problem `code` of the API. */
export class TakeoverRequestError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly type?: string;
  readonly blockingReason?: PortfolioTakeoverBlockReason;
  readonly errors: PortfolioTakeoverError[];

  constructor(result: ResultLike) {
    super('Portfolio takeover request failed.');
    const problem = result.error as
      | {
          code?: string;
          type?: string;
          blockingReason?: PortfolioTakeoverBlockReason;
          errors?: PortfolioTakeoverError[];
        }
      | undefined;
    this.status = result.response?.status ?? 0;
    this.code = problem?.code;
    this.type = problem?.type;
    this.blockingReason = problem?.blockingReason;
    this.errors = problem?.errors ?? [];
  }
}

export function takeoverRequestError(result: ResultLike) {
  return new TakeoverRequestError(result);
}

const blocked: Record<PortfolioTakeoverBlockReason, string> = {
  NOT_SUPPORTED: 'Ce compte ne permet pas la reprise de positions.',
  ALREADY_TAKEN_OVER: 'Les positions de ce compte ont déjà été reprises.',
  ACTIVE_VALUATION: 'Ce compte a déjà une valorisation : la reprise n’est plus possible.',
  SECURITY_OPERATION:
    'Ce compte contient déjà des opérations sur titres : la reprise n’est plus possible.',
  CASH_OPERATION_BEFORE:
    'Ce compte a une opération sur les espèces à cette date ou avant : choisissez une date plus récente.',
};

const messages: Record<string, string> = {
  portfolio_takeover_exists: blocked.ALREADY_TAKEN_OVER,
  portfolio_takeover_not_supported: blocked.NOT_SUPPORTED,
  operation_limit_reached: 'La limite de 10 000 opérations actives pour ce compte est atteinte.',
  valuation_limit_reached: 'La limite de valorisations actives pour ce compte est atteinte.',
  portfolio_operation_conflict: 'Une autre écriture est en cours sur ce compte. Réessayez.',
  portfolio_valuation_conflict: 'Une autre écriture est en cours sur ce compte. Réessayez.',
  portfolio_payload_too_large: 'La requête est trop volumineuse.',
};

/** General message of a refused save; per-line refusals are shown next to their field. */
export function takeoverSaveMessage(error: unknown): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof TakeoverRequestError) || error.status === 0)
    return 'La connexion a échoué. Vérifiez votre connexion puis réessayez.';
  if (error.type === '/problems/period-closed')
    return 'Le mois de reprise est clôturé. Rouvrez-le avant d’enregistrer la reprise.';
  if (error.code === 'portfolio_takeover_not_empty')
    return blocked[error.blockingReason ?? 'SECURITY_OPERATION'];
  if (error.code === 'invalid_portfolio_takeover' && error.errors.length > 0) return undefined;
  if (error.code && messages[error.code]) return messages[error.code];
  return 'L’enregistrement a été refusé. Réessayez.';
}
