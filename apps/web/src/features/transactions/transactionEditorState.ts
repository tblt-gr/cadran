import type { Transaction } from '@cadran/api-client';

/** Which transaction editor the page shows: a fresh draft, an existing row, or none. */
export type Editor = Transaction | 'create' | null;

/** The toast shown after a write settles, keyed by which one just happened. */
export type Saved = 'duplicated' | 'saved' | 'transferSaved' | 'voided' | null;
