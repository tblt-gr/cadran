import type { ParseKeys } from 'i18next';
import type { TFunction } from 'i18next';
import type { InstrumentCandidate, InstrumentProblem, Problem } from '@cadran/api-client';

export class InstrumentRequestError extends Error {
  readonly code?: string;
  readonly candidates: InstrumentCandidate[];
  readonly existingId?: string | null;

  constructor(result: { error?: unknown; response?: Response }) {
    super('Instrument request failed.');
    const problem = result.error as InstrumentProblem | Problem | undefined;
    this.code = problem && 'code' in problem ? problem.code : undefined;
    this.candidates = problem && 'candidates' in problem ? (problem.candidates ?? []) : [];
    this.existingId = problem && 'existingId' in problem ? problem.existingId : undefined;
  }
}

export function instrumentRequestError(result: { error?: unknown; response?: Response }) {
  return new InstrumentRequestError(result);
}

export interface InstrumentFieldErrors {
  isin?: string;
  ticker?: string;
  venueMic?: string;
}

const fieldMessages: Record<string, keyof InstrumentFieldErrors> = {
  invalid_instrument_isin: 'isin',
  invalid_instrument_ticker: 'ticker',
  invalid_instrument_venue: 'venueMic',
};
const messageCodes = [
  'instrument_response_incompatible',
  'stale_instrument_version',
  'instrument_identity_locked',
  'instrument_limit_reached',
];

export function instrumentFieldErrors(error: unknown, t: TFunction): InstrumentFieldErrors {
  if (!(error instanceof InstrumentRequestError) || !error.code) return {};
  const field = fieldMessages[error.code];
  return field ? { [field]: t(`investments.instruments.fieldErrors.${field}`) } : {};
}

/** General message for a save failure; duplicates and field errors are shown elsewhere. */
export function instrumentSaveMessage(error: unknown, t: TFunction): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof InstrumentRequestError)) {
    return t('investments.instruments.errors.connection');
  }
  if (
    error.code === 'instrument_duplicate_candidates' ||
    error.code === 'instrument_isin_exists' ||
    (error.code && error.code in fieldMessages)
  ) {
    return undefined;
  }
  return error.code && messageCodes.includes(error.code)
    ? t(`investments.instruments.errors.${error.code}` as ParseKeys)
    : t('investments.instruments.errors.refused');
}

export function isStaleInstrumentError(error: unknown): boolean {
  return (
    error instanceof InstrumentRequestError &&
    (error.code === 'stale_instrument_version' ||
      error.code === 'instrument_already_active' ||
      error.code === 'instrument_already_inactive')
  );
}
