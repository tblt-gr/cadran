import i18n from '@/i18n';
import type { ParseKeys } from 'i18next';
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

const tk = (key: string, options?: Record<string, unknown>) =>
  i18n.t(`portfolio.takeover.errors.${key}` as ParseKeys, options);

const messageKeys: Record<string, string> = {
  portfolio_takeover_exists: 'blocked.ALREADY_TAKEN_OVER',
  portfolio_takeover_not_supported: 'blocked.NOT_SUPPORTED',
  operation_limit_reached: 'codes.operation_limit_reached',
  valuation_limit_reached: 'codes.valuation_limit_reached',
  portfolio_operation_conflict: 'codes.write_conflict',
  portfolio_valuation_conflict: 'codes.write_conflict',
  portfolio_payload_too_large: 'codes.portfolio_payload_too_large',
};

/** General message of a refused save; per-line refusals are shown next to their field. */
export function takeoverSaveMessage(error: unknown): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof TakeoverRequestError) || error.status === 0) return tk('connection');
  if (error.type === '/problems/period-closed') return tk('closedMonth');
  if (error.code === 'portfolio_takeover_not_empty')
    return tk(`blocked.${error.blockingReason ?? 'SECURITY_OPERATION'}`);
  if (error.code === 'invalid_portfolio_takeover' && error.errors.length > 0) return undefined;
  if (error.code && messageKeys[error.code]) return tk(messageKeys[error.code]!);
  return tk('refused');
}
