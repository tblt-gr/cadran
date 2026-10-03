import type { PortfolioValuationProblem } from '@cadran/api-client';

type ResultLike = { error?: unknown; response?: Response };

/** A refused valuation request, classified by the problem `code` of the API. */
export class ValuationRequestError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly type?: string;
  /** JSON pointer of the offending member, such as `/prices/0/price`. */
  readonly field?: string;

  constructor(result: ResultLike) {
    super('Portfolio valuation request failed.');
    const problem = result.error as Partial<PortfolioValuationProblem> | undefined;
    this.status = result.response?.status ?? 0;
    this.code = problem?.code;
    this.type = problem?.type;
    this.field = problem?.field;
  }
}

export function valuationRequestError(result: ResultLike) {
  return new ValuationRequestError(result);
}

const fieldTexts: Record<string, string> = {
  invalid_valuation_total: 'La valeur totale est invalide.',
  invalid_valuation_price: 'Le prix est invalide.',
  price_precision_exceeded: 'Le prix dépasse la précision de l’instrument.',
  invalid_price_date: 'La date du prix doit précéder ou égaler la date de valorisation.',
  invalid_valuation_date: 'La date doit être comprise entre l’ouverture du compte et aujourd’hui.',
  operation_asset_mismatch: 'Le montant doit être exprimé dans la devise du compte.',
  valuation_field_not_allowed: 'Ce champ n’est pas autorisé pour ce type de saisie.',
};

/** Field-level refusals keyed by the JSON pointer the server returned. */
export function valuationFieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ValuationRequestError) || !error.code || !error.field) return {};
  const text = fieldTexts[error.code];
  return text ? { [error.field]: text } : {};
}

const messages: Record<string, string> = {
  account_not_portfolio: 'Ce compte n’est pas suivi en mode portefeuille.',
  portfolio_account_inactive: 'Ce compte est clos ou archivé : il n’accepte plus de valorisation.',
  valuation_exists:
    'Une valorisation existe déjà à cette date. Rechargez la liste puis utilisez Remplacer.',
  stale_valuation_version:
    'Cette valorisation a été modifiée ailleurs. Fermez cette fenêtre pour recharger la liste.',
  valuation_limit_reached: 'La limite de 5 000 valorisations actives pour ce compte est atteinte.',
  portfolio_valuation_conflict: 'Une autre écriture est en cours sur ce compte. Réessayez.',
  valuation_price_entry_missing:
    'Un prix doit être fourni pour chaque position détenue à cette date.',
  unknown_valuation_instrument: 'Un prix concerne un instrument non détenu à cette date.',
  invalid_valuation_detail: 'Le type de saisie est invalide.',
  invalid_valuation_payload: 'La requête est invalide. Vérifiez les champs.',
  portfolio_payload_too_large: 'La requête est trop volumineuse.',
  cash_amount_too_large: 'Les montants sont trop grands pour être additionnés exactement.',
  portfolio_valuation_not_found: 'Cette valorisation n’existe plus.',
};

/** General message for a refused request; field-level refusals are shown next to their field. */
export function valuationSaveMessage(error: unknown): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof ValuationRequestError) || error.status === 0) {
    return 'La connexion a échoué. Vérifiez votre connexion puis réessayez.';
  }
  if (error.type === '/problems/period-closed') {
    return 'Le mois concerné est clôturé. Rouvrez-le avant d’enregistrer cette valorisation.';
  }
  if (error.code && error.field && fieldTexts[error.code]) return undefined;
  if (error.code && messages[error.code]) return messages[error.code];
  if (error.code && fieldTexts[error.code]) return fieldTexts[error.code];
  return 'L’enregistrement a été refusé. Vérifiez les champs.';
}

export function isStaleValuationError(error: unknown): boolean {
  return (
    error instanceof ValuationRequestError &&
    (error.code === 'stale_valuation_version' || error.code === 'portfolio_valuation_not_found')
  );
}
