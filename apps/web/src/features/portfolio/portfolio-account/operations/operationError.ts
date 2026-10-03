import type { PortfolioOperationProblem } from '@cadran/api-client';
import type { OperationFieldErrors } from './operation-form/operationRequest';

type ResultLike = { error?: unknown; response?: Response };

/** A refused portfolio request, classified by the problem `code` of the API. */
export class OperationRequestError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly type?: string;
  readonly field?: string;
  readonly expectedGrossAmount?: string;
  readonly instrumentId?: string;
  readonly tradeDate?: string;

  constructor(result: ResultLike) {
    super('Portfolio operation request failed.');
    const problem = result.error as Partial<PortfolioOperationProblem> | undefined;
    this.status = result.response?.status ?? 0;
    this.code = problem?.code;
    this.type = problem?.type;
    this.field = problem?.field;
    this.expectedGrossAmount = problem?.expectedGrossAmount;
    this.instrumentId = problem?.instrumentId;
    this.tradeDate = (problem as { tradeDate?: string } | undefined)?.tradeDate;
  }
}

export function operationRequestError(result: ResultLike) {
  return new OperationRequestError(result);
}

const fieldByCode: Record<string, keyof OperationFieldErrors> = {
  invalid_operation_quantity: 'quantity',
  quantity_precision_exceeded: 'quantity',
  price_precision_exceeded: 'unitPrice',
  instrument_inactive: 'instrument',
  instrument_currency_mismatch: 'instrument',
  unknown_instrument: 'instrument',
  invalid_corporate_action: 'counterInstrument',
  invalid_operation_date: 'tradeDate',
  invalid_settlement_date: 'settlementDate',
  operation_amount_mismatch: 'grossAmount',
  linked_transfer_mismatch: 'linkedTransaction',
  linked_transfer_taken: 'linkedTransaction',
  invalid_operation_amount: 'grossAmount',
  invalid_operation_text: 'note',
};
const fieldByName: Record<string, keyof OperationFieldErrors> = {
  instrumentId: 'instrument',
  counterInstrumentId: 'counterInstrument',
  linkedTransactionId: 'linkedTransaction',
  quantity: 'quantity',
  unitPrice: 'unitPrice',
  grossAmount: 'grossAmount',
  fees: 'fees',
  taxes: 'taxes',
  costBasis: 'costBasis',
  counterQuantity: 'counterQuantity',
  ratio: 'ratio',
  costAllocationRatio: 'costAllocationRatio',
  reference: 'reference',
  note: 'note',
  settlementDate: 'settlementDate',
  tradeDate: 'tradeDate',
};
const fieldTexts: Record<string, string> = {
  invalid_operation_quantity: 'La quantité est invalide.',
  quantity_precision_exceeded: 'La quantité dépasse la précision de l’instrument.',
  price_precision_exceeded: 'Le prix dépasse la précision de l’instrument.',
  instrument_inactive: 'Cet instrument est inactif.',
  instrument_currency_mismatch: 'La devise de l’instrument diffère de celle du compte.',
  unknown_instrument: 'Cet instrument est introuvable.',
  invalid_corporate_action: 'L’opération sur titres est incohérente.',
  invalid_operation_date: 'La date doit être comprise entre l’ouverture du compte et aujourd’hui.',
  invalid_settlement_date:
    'La date de règlement doit suivre la date d’opération de 31 jours au plus.',
  linked_transfer_mismatch: 'Ce virement ne correspond pas au montant, à la date ou au sens.',
  linked_transfer_taken: 'Ce virement est déjà lié à une autre opération.',
  invalid_operation_amount: 'Le montant est invalide.',
  invalid_operation_text: 'Le texte contient un caractère interdit ou est trop long.',
  operation_field_required: 'Ce champ est obligatoire pour ce type.',
  operation_field_not_allowed: 'Ce champ n’est pas autorisé pour ce type.',
};

/** Message for the gross amount refusal, with the exact value the server expects. */
export function mismatchMessage(expected: string | undefined): string {
  return expected
    ? `Le montant brut ne correspond pas à quantité × prix. Montant attendu : ${expected}.`
    : 'Le montant brut ne correspond pas à quantité × prix.';
}

export function operationFieldErrors(error: unknown): OperationFieldErrors {
  if (!(error instanceof OperationRequestError) || !error.code) return {};
  if (error.code === 'operation_amount_mismatch') {
    return { grossAmount: mismatchMessage(error.expectedGrossAmount) };
  }
  const named = error.field ? fieldByName[error.field] : undefined;
  if (error.code === 'operation_field_required' || error.code === 'operation_field_not_allowed') {
    return named ? { [named]: fieldTexts[error.code]! } : {};
  }
  const field =
    (named && error.code === 'invalid_operation_amount' ? named : undefined) ??
    fieldByCode[error.code];
  return field && fieldTexts[error.code] ? { [field]: fieldTexts[error.code]! } : {};
}

const messages: Record<string, string> = {
  account_not_portfolio: 'Ce compte n’est pas suivi en mode portefeuille.',
  portfolio_account_inactive: 'Ce compte est clos ou archivé : il n’accepte plus d’opération.',
  operation_limit_reached: 'La limite de 10 000 opérations actives pour ce compte est atteinte.',
  stale_operation_version:
    'Cette opération a été modifiée ailleurs. Fermez cette fenêtre pour recharger la liste.',
  operation_voided: 'Cette opération est déjà annulée ou remplacée.',
  portfolio_operation_conflict: 'Une autre écriture est en cours sur ce compte. Réessayez.',
  unsupported_operation_type: 'Ce type d’opération n’est pas pris en charge.',
  portfolio_payload_too_large: 'La requête est trop volumineuse.',
};

/** General message for a refused save; field-level refusals are shown next to their field. */
export function operationSaveMessage(
  error: unknown,
  instruments: ReadonlyArray<{ id: string; name: string } | null> = [],
): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof OperationRequestError) || error.status === 0) {
    return 'La connexion a échoué. Vérifiez votre connexion puis réessayez.';
  }
  if (error.type === '/problems/period-closed') {
    return 'Le mois concerné est clôturé. Rouvrez-le avant d’enregistrer cette opération.';
  }
  if (error.code === 'position_would_go_negative') {
    const instrument = instruments.find((item) => item?.id === error.instrumentId)?.name;
    return `Cette opération rendrait la position ${instrument ?? 'concernée'} négative le ${error.tradeDate ?? 'à cette date'}.`;
  }
  if (error.code && messages[error.code]) return messages[error.code];
  if (error.code && Object.keys(operationFieldErrors(error)).length > 0) return undefined;
  return 'L’enregistrement a été refusé. Vérifiez les champs.';
}

export function isStaleOperationError(error: unknown): boolean {
  return (
    error instanceof OperationRequestError &&
    (error.code === 'stale_operation_version' || error.code === 'operation_voided')
  );
}
