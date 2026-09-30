import type { GoalVersionHistoryItem } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { FIELD_LABELS, type GoalVersionField, useGoalVersionValue } from './goalVersionValue';
import styles from './GoalHistory.module.css';

function scheduleValues(version: GoalVersionHistoryItem): string[] | null {
  return version.schedule?.parts.map((part) => part.amount) ?? null;
}

export function GoalVersionDiff({
  after,
  assetCode,
  before,
}: {
  after: GoalVersionHistoryItem;
  assetCode: string;
  before: GoalVersionHistoryItem;
}) {
  const { t } = useTranslation();
  const format = useGoalVersionValue(assetCode);
  const rows: Array<{
    field: GoalVersionField;
    before: string | string[] | null;
    after: string | string[] | null;
  }> = [
    { field: 'targetAmount' as const, before: before.targetAmount, after: after.targetAmount },
    { field: 'ratio' as const, before: before.ratio, after: after.ratio },
    {
      field: 'scheduleMode' as const,
      before: before.schedule?.mode ?? null,
      after: after.schedule?.mode ?? null,
    },
    { field: 'schedule' as const, before: scheduleValues(before), after: scheduleValues(after) },
  ].filter((row) => JSON.stringify(row.before) !== JSON.stringify(row.after));
  const definitionRows = rows.filter((row) => row.field !== 'schedule');
  return (
    <section aria-labelledby="goal-version-diff-title" className={styles.diff}>
      <h3 id="goal-version-diff-title">{t('goals.history.comparison')}</h3>
      {rows.length === 0 ? <p>{t('goals.history.noChanges')}</p> : null}
      {definitionRows.length ? (
        <div className={styles.tableWrap}>
          <table className={styles.table}>
            <caption className="sr-only">{t('goals.history.comparison')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('goals.history.field')}</th>
                <th scope="col">
                  {t('goals.history.versionValue', { version: before.versionNo })}
                </th>
                <th scope="col">{t('goals.history.versionValue', { version: after.versionNo })}</th>
              </tr>
            </thead>
            <tbody>
              {definitionRows.map((row) => (
                <tr key={row.field}>
                  <th scope="row">{t(FIELD_LABELS[row.field])}</th>
                  <td>{format(row.before, row.field)}</td>
                  <td>{format(row.after, row.field)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {rows.some((row) => row.field === 'schedule') ? (
        <ScheduleComparison after={after} assetCode={assetCode} before={before} />
      ) : null}
    </section>
  );
}

function ScheduleComparison({
  after,
  assetCode,
  before,
}: {
  after: GoalVersionHistoryItem;
  assetCode: string;
  before: GoalVersionHistoryItem;
}) {
  const { i18n, t } = useTranslation();
  const format = useGoalVersionValue(assetCode);
  const parts = Array.from({ length: 12 }, (_, index) => ({
    after: after.schedule?.parts[index]?.amount ?? null,
    before: before.schedule?.parts[index]?.amount ?? null,
    index,
  }));
  if (parts.every((part) => part.before === null && part.after === null)) return null;
  const uniform = parts.every(
    (part) => part.before === parts[0]?.before && part.after === parts[0]?.after,
  );
  const displayedParts = uniform ? parts.slice(0, 1) : parts;
  return (
    <div className={styles.tableWrap}>
      <table className={styles.table}>
        <caption>{t('goals.history.scheduleComparison')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('goals.history.month')}</th>
            <th scope="col">{t('goals.history.versionValue', { version: before.versionNo })}</th>
            <th scope="col">{t('goals.history.versionValue', { version: after.versionNo })}</th>
          </tr>
        </thead>
        <tbody>
          {displayedParts.map((part) => (
            <tr key={part.index}>
              <th scope="row">
                {uniform
                  ? t('goals.history.everyMonth')
                  : new Intl.DateTimeFormat(i18n.language, {
                      month: 'long',
                      timeZone: 'UTC',
                    }).format(new Date(Date.UTC(2020, part.index, 1)))}
              </th>
              <td>{format(part.before, 'schedule')}</td>
              <td>{format(part.after, 'schedule')}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
