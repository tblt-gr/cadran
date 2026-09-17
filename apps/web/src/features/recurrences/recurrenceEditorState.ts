import type { Recurrence, RecurrenceCandidate } from '@cadran/api-client';

/** Which recurrence editor the page shows: a fresh draft (from a candidate or blank), an
 * existing recurrence, or none. */
export type Editor = 'create' | RecurrenceCandidate | Recurrence | null;
