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
const fieldTexts: Record<keyof InstrumentFieldErrors, string> = {
  isin: 'Cet ISIN est invalide.',
  ticker: 'Ce ticker est invalide.',
  venueMic: 'Ce code MIC est invalide.',
};
const messages: Record<string, string> = {
  stale_instrument_version:
    'Cet instrument a été modifié ailleurs. Fermez cette fenêtre pour recharger la liste, puis réessayez.',
  instrument_identity_locked:
    'L’ISIN, la devise, les précisions et le type fonds en euros sont figés après la première utilisation.',
  instrument_limit_reached: 'La limite d’instruments de cet espace est atteinte.',
};

export function instrumentFieldErrors(error: unknown): InstrumentFieldErrors {
  if (!(error instanceof InstrumentRequestError) || !error.code) return {};
  const field = fieldMessages[error.code];
  return field ? { [field]: fieldTexts[field] } : {};
}

/** General message for a save failure; duplicates and field errors are shown elsewhere. */
export function instrumentSaveMessage(error: unknown): string | undefined {
  if (!error) return undefined;
  if (!(error instanceof InstrumentRequestError)) {
    return 'La connexion a échoué. Vérifiez votre connexion puis réessayez.';
  }
  if (
    error.code === 'instrument_duplicate_candidates' ||
    error.code === 'instrument_isin_exists' ||
    (error.code && error.code in fieldMessages)
  ) {
    return undefined;
  }
  return (
    (error.code && messages[error.code]) || 'La modification a été refusée. Vérifiez les champs.'
  );
}

export function isStaleInstrumentError(error: unknown): boolean {
  return (
    error instanceof InstrumentRequestError &&
    (error.code === 'stale_instrument_version' ||
      error.code === 'instrument_already_active' ||
      error.code === 'instrument_already_inactive')
  );
}
