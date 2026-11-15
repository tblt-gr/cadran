export type CommonProblemKind = 'stale' | 'conflict' | 'invalid' | 'unauthorized' | 'network';

const STALE_VERSION = '/problems/stale-version';

/**
 * Classifies a refused API request. A problem `type` specific to the feature
 * always wins; the status only answers when the feature has nothing to say.
 * 401 and 403 are one answer to the user (sign in again), and a transport
 * failure or an unexpected status falls through to `network`.
 */
export function classifyProblem<K extends string>(
  status: number,
  type: string | undefined,
  specific: Readonly<Record<string, K>>,
): K | CommonProblemKind {
  if (type !== undefined && Object.hasOwn(specific, type)) return specific[type] as K;
  if (type === STALE_VERSION) return 'stale';
  if (status === 401 || status === 403) return 'unauthorized';
  if (status === 409) return 'conflict';
  if (status === 400 || status === 422) return 'invalid';

  return 'network';
}
