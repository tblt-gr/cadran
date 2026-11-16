import type { MultiYearReport } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { multiYearYearNotes } from './multiYearYearNotes';
import localStyles from './ContinuousDetailedReport.module.css';

/** The qualifications of one year (partial year, policy change) as small notes. */
export function YearNotes({ year }: { year: MultiYearReport['years'][number] }) {
  const { t } = useTranslation();

  return (
    <>
      {multiYearYearNotes(year).map((note) => (
        <small className={localStyles.note} key={note.key}>
          {t(note.key, note.params)}
        </small>
      ))}
    </>
  );
}
