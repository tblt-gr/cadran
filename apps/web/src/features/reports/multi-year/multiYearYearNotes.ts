import type { MultiYearYear } from '@cadran/api-client';

type MultiYearYearNoteKey = 'reports.multiYear.snapshotAndLive';

export interface MultiYearYearNote {
  key: MultiYearYearNoteKey;
  params?: Record<string, number>;
}

/** The mixed-snapshot cue, shared by the table and the mobile cards. */
export function multiYearYearNotes(year: MultiYearYear): MultiYearYearNote[] {
  const notes: MultiYearYearNote[] = [];
  if (year.snapshotMonths > 0 && year.liveMonths > 0) {
    notes.push({
      key: 'reports.multiYear.snapshotAndLive',
      params: { snapshot: year.snapshotMonths, live: year.liveMonths },
    });
  }

  return notes;
}
