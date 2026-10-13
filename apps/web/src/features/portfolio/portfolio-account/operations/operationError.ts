import type { PortfolioOperationProblem } from '@cadran/api-client';
import type { OperationFieldErrors } from './operation-form/operationRequest';

type ResultLike = { error?: unknown; response?: Response };

/** A refused portfolio request, classified by the problem `code` of the API. */
export class OperationRequestError extends Error {
  readonly legIndex?: number;
  readonly expectedAmount?: string;
  readonly expectedIncomingAmount?: string;
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
    this.legIndex = problem?.legIndex;
    this.expectedAmount = problem?.expectedAmount;
    this.expectedIncomingAmount = problem?.expectedIncomingAmount;
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
  instrument_not_euro_fund: 'instrument',
  invalid_year: 'rateYear',
  invalid_interest_basis: 'interestBasis',
  switch_same_support: 'legs',
  unknown_leg_instrument: 'legs',
  operation_asset_mismatch: 'legs',
  duplicate_leg_instrument: 'legs',
  invalid_leg_direction: 'legs',
  premium_allocation_exceeds_net: 'grossAmount',
  surrender_exceeds_available: 'grossAmount',
  invalid_surrender_amounts: 'grossAmount',
  full_surrender_not_balanced: 'grossAmount',
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
  interestBasis: 'interestBasis',
  rateYear: 'rateYear',
  withheldIncomeTax: 'withheldIncomeTax',
  withheldSocialContributions: 'withheldSocialContributions',
  legs: 'legs',
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
  instrument_not_euro_fund: 'Choisissez un fonds en euros.',
  invalid_year: 'L’année doit être comprise entre la souscription et l’opération.',
  invalid_interest_basis: 'Choisissez une base du taux.',
  premium_allocation_exceeds_net: 'Les supports dépassent le versement net de frais.',
  surrender_exceeds_available: 'Le rachat dépasse les liquidités et les supports vendus.',
  invalid_surrender_amounts: 'Les frais et prélèvements dépassent le montant brut du rachat.',
  full_surrender_not_balanced: 'Le rachat total doit solder exactement les liquidités.',
  switch_same_support: 'Un support ne peut pas être vendu et acheté dans le même arbitrage.',
  unknown_leg_instrument: 'Ce support est introuvable.',
  duplicate_leg_instrument: 'Ce support figure déjà dans l’opération.',
  invalid_leg_direction: 'Le sens de ce support ne correspond pas à l’opération.',
  euro_fund_price_fixed: 'Le prix d’un fonds en euros est fixé à 1.',
  operation_asset_mismatch: 'La devise du support doit correspondre au compte.',
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
function mismatchMessage(expected: string | undefined): string {
  return expected
    ? `Le montant brut ne correspond pas à quantité × prix. Montant attendu : ${expected}.`
    : 'Le montant brut ne correspond pas à quantité × prix.';
}

export function operationFieldErrors(error: unknown): OperationFieldErrors {
  if (!(error instanceof OperationRequestError) || !error.code) return {};
  if (error.code === 'switch_not_balanced')
    return {
      legs: `L’arbitrage doit être équilibré. À réinvestir : ${error.expectedIncomingAmount ?? '-'}.`,
    };
  if (error.legIndex !== undefined) {
    const field =
      error.code === 'leg_amount_mismatch'
        ? 'amount'
        : error.code === 'quantity_precision_exceeded' ||
            error.code === 'invalid_operation_quantity'
          ? 'quantity'
          : error.code === 'price_precision_exceeded' || error.code === 'euro_fund_price_fixed'
            ? 'unitPrice'
            : 'row';
    const message =
      error.code === 'leg_amount_mismatch'
        ? `Le montant ne correspond pas aux parts × valeur liquidative. Montant attendu : ${error.expectedAmount ?? '-'}.`
        : (fieldTexts[error.code] ?? 'Ce support est invalide. Vérifiez ses valeurs.');
    return { [`legs.${error.legIndex}.${field}`]: message };
  }
  if (error.code === 'full_surrender_not_balanced')
    return {
      grossAmount: `Le rachat total doit solder les liquidités. Montant brut attendu : ${error.expectedGrossAmount ?? '-'}.`,
    };
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
  full_surrender_positions_remaining:
    'Le rachat total laisse des supports détenus. Reprenez toutes les positions.',
  operations_after_full_surrender:
    'Des opérations suivent cette date. Corrigez-les avant le rachat total.',
  too_many_operation_legs: '20 supports au maximum sont autorisés.',
  life_insurance_contract_required: 'Renseignez d’abord le contrat avant de saisir une opération.',
  life_insurance_contract_closed: 'Ce contrat est clos à la date de l’opération.',
  operation_type_not_allowed_for_account: 'Ce type d’opération n’est pas autorisé sur ce contrat.',
  euro_fund_price_fixed: 'Un fonds en euros vaut toujours 1 € par part.',
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
