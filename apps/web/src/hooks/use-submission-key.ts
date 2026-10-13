import { useState } from 'react';

/**
 * One Idempotency-Key per distinct body of a submission: a retry of any body already sent replays
 * its first response, even after an edit and a revert (the server may have committed it while the
 * response was lost). `release` ends the submission once it succeeded, so the next modal opened
 * from the same page does not replay it.
 */
export class SubmissionKey {
  private readonly keys = new Map<string, string>();

  keyFor(body: unknown): string {
    const serialized = JSON.stringify(body);
    let key = this.keys.get(serialized);
    if (key === undefined) {
      key = crypto.randomUUID();
      this.keys.set(serialized, key);
    }
    return key;
  }

  release(): void {
    this.keys.clear();
  }
}

export function useSubmissionKey(): SubmissionKey {
  return useState(() => new SubmissionKey())[0];
}
