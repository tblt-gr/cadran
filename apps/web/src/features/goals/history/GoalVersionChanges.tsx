import type { GoalVersionHistoryItem } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { FIELD_LABELS, useGoalVersionValue } from './goalVersionValue';
import styles from './GoalHistory.module.css';

/** The changes the server recorded for each version, as opposed to a free comparison. */
export function GoalVersionChanges({
  assetCode,
  items,
}: {
  assetCode: string;
  items: GoalVersionHistoryItem[];
}) {
  const { t } = useTranslation();
  const format = useGoalVersionValue(assetCode);
  const changed = items.filter((item) => item.changes.length > 0);
  if (changed.length === 0) return null;
  return (
    <section aria-labelledby="goal-version-changes-title" className={styles.diff}>
      <h3 id="goal-version-changes-title">{t('goals.history.recordedChanges')}</h3>
      {changed.map((item) => (
        <div className={styles.tableWrap} key={item.versionNo}>
          <table className={styles.table}>
            <caption>{t('goals.history.changesOf', { version: item.versionNo })}</caption>
            <thead>
              <tr>
                <th scope="col">{t('goals.history.field')}</th>
                <th scope="col">{t('goals.history.before')}</th>
                <th scope="col">{t('goals.history.after')}</th>
              </tr>
            </thead>
            <tbody>
              {item.changes.map((change) => (
                <tr key={change.field}>
                  <th scope="row">{t(FIELD_LABELS[change.field])}</th>
                  <td>{format(change.before, change.field)}</td>
                  <td>{format(change.after, change.field)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ))}
    </section>
  );
}
