import i18n from '@/i18n';
import type { ParseKeys } from 'i18next';
import type { PortfolioValuationProblem } from '@cadran/api-client';

type ResultLike = { error?: unknown; response?: Response };

/** A refused valuation request, classified by the problem `code` of the API. */
class ValuationRequestError extends Error {
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

const ve = (key: string) => i18n.t(`portfolio.valuations.errors.${key}` as ParseKeys);

const fieldCodes = new Set([
  'invalid_valuation_total',
  'invalid_valuation_price',
  'price_precision_exceeded',
  'invalid_price_date',
  'invalid_valuation_date',
  'operation_asset_mismatch',
  'valuation_field_not_allowed',
]);

/** Field-level refusals keyed by the JSON pointer the server returned. */
export function valuationFieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ValuationRequestError) || !error.code || !error.field) return {};
  return fieldCodes.has(error.code) ? { [error.field]: ve(`field.${error.code}`) } : {};
}

const messageCodes = new Set([
  'account_not_portfolio',
  'portfolio_account_inactive',
  'valuation_exists',
  'stale_valuation_version',
  'valuation_limit_reached',
  'portfolio_valuation_conflict',
  'valuation_price_entry_missing',
  'unknown_valuation_instrument',
  'invalid_valuation_detail',
  'invalid_valuation_payload',
  'portfolio_payload_too_large',
  'cash_amount_too_large',
  'portfolio_valuation_not_found',
]);

/** General message for a refused request; field-level refusals are shown next to their field. */
export function valuationSaveMessage(error: unknown): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof ValuationRequestError) || error.status === 0) return ve('connection');
  if (error.type === '/problems/period-closed') return ve('closedMonth');
  if (error.code && error.field && fieldCodes.has(error.code)) return undefined;
  if (error.code && messageCodes.has(error.code)) return ve(`message.${error.code}`);
  if (error.code && fieldCodes.has(error.code)) return ve(`field.${error.code}`);
  return ve('refused');
}

export function isStaleValuationError(error: unknown): boolean {
  return (
    error instanceof ValuationRequestError &&
    (error.code === 'stale_valuation_version' || error.code === 'portfolio_valuation_not_found')
  );
}
