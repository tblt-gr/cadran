import type { MetricPolicyProblem } from '@cadran/api-client';

export type MetricPolicyErrorKind =
  | 'activation_limit'
  | 'already_active'
  | 'definition_exists'
  | 'invalid'
  | 'network'
  | 'stale'
  | 'unauthorized'
  | 'unknown_version'
  | 'version_limit';

const CODE_KINDS: Record<string, MetricPolicyErrorKind> = {
  activation_limit: 'activation_limit',
  policy_already_active: 'already_active',
  policy_definition_exists: 'definition_exists',
  policy_version_limit: 'version_limit',
  stale_active_policy: 'stale',
  unknown_policy_version: 'unknown_version',
};

/** A refused metric policy request, classified by problem code before status. */
export class MetricPolicyRequestError extends Error {
  readonly kind: MetricPolicyErrorKind;

  constructor(status: number, problem: MetricPolicyProblem | undefined) {
    super(`Metric policy request failed with status ${status}.`);
    const byCode = problem?.code === undefined ? undefined : CODE_KINDS[problem.code];
    if (byCode !== undefined) this.kind = byCode;
    else if (status === 401 || status === 403) this.kind = 'unauthorized';
    else if (status === 422) this.kind = 'invalid';
    else this.kind = 'network';
  }
}

export function metricPolicyRequestError(result: { error?: unknown; response?: Response }) {
  return new MetricPolicyRequestError(
    result.response?.status ?? 0,
    result.error as MetricPolicyProblem | undefined,
  );
}

export function metricPolicyErrorKind(error: unknown): MetricPolicyErrorKind | null {
  if (error === null || error === undefined) return null;
  return error instanceof MetricPolicyRequestError ? error.kind : 'network';
}
