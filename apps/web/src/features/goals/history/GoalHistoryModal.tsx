import type { GoalVersionHistoryItem } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { formatGoalMonth } from '@/features/goals/goalMonth';
import { GoalVersionChanges } from './GoalVersionChanges';
import { GoalVersionDiff } from './GoalVersionDiff';
import { HistoryInfo } from './HistoryInfo';
import styles from './GoalHistory.module.css';

export function GoalHistoryModal({
  assetCode,
  close,
  items,
}: {
  assetCode: string;
  close: () => void;
  items: GoalVersionHistoryItem[];
}) {
  const { i18n, t } = useTranslation();
  const [afterNo, setAfterNo] = useState(() => items[0]?.versionNo ?? 0);
  const [beforeNo, setBeforeNo] = useState(() => items[1]?.versionNo ?? items[0]?.versionNo ?? 0);
  const after = items.find((item) => item.versionNo === afterNo);
  const before = items.find((item) => item.versionNo === beforeNo);
  return (
    <Modal close={close} eyebrow={t('goals.history.eyebrow')} title={t('goals.history.title')}>
      {items.length === 0 ? (
        <p>{t('goals.history.empty')}</p>
      ) : (
        <div className={styles.history}>
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <caption>
                {t('goals.history.caption')}
                <HistoryInfo
                  label={t('goals.history.explainDates')}
                  text={t('goals.history.effectiveHint')}
                />
              </caption>
              <thead>
                <tr>
                  <th scope="col">{t('goals.history.version')}</th>
                  <th scope="col">{t('goals.history.effectiveFrom')}</th>
                  <th scope="col">{t('goals.history.author')}</th>
                  <th scope="col">{t('goals.history.createdAt')}</th>
                  <th scope="col">{t('goals.history.reason')}</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.versionNo}>
                    <th scope="row">
                      {t('goals.history.versionValue', { version: item.versionNo })}
                    </th>
                    <td>{formatGoalMonth(item.effectiveFrom, i18n.language)}</td>
                    <td>{item.createdBy.displayName}</td>
                    <td>
                      {new Intl.DateTimeFormat(i18n.language, {
                        dateStyle: 'medium',
                        timeStyle: 'short',
                      }).format(new Date(item.createdAt))}
                    </td>
                    <td>
                      {item.retroactive ? (
                        <>
                          <span className={styles.retroactive}>
                            {t('goals.history.retroactive')}
                          </span>{' '}
                          {item.reason}
                        </>
                      ) : (
                        t('goals.history.notRetroactive')
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <GoalVersionChanges assetCode={assetCode} items={items} />
          <fieldset className={styles.compare}>
            <legend>
              {t('goals.history.chooseVersions')}
              <HistoryInfo
                label={t('goals.history.explainComparison')}
                text={t('goals.history.comparisonHint')}
              />
            </legend>
            <label>
              {t('goals.history.referenceVersion')}
              <select
                onChange={(event) => setBeforeNo(Number(event.target.value))}
                value={beforeNo}
              >
                {items.map((item) => (
                  <option key={item.versionNo} value={item.versionNo}>
                    {t('goals.history.versionValue', { version: item.versionNo })}
                  </option>
                ))}
              </select>
            </label>
            <label>
              {t('goals.history.comparedVersion')}
              <select onChange={(event) => setAfterNo(Number(event.target.value))} value={afterNo}>
                {items.map((item) => (
                  <option key={item.versionNo} value={item.versionNo}>
                    {t('goals.history.versionValue', { version: item.versionNo })}
                  </option>
                ))}
              </select>
            </label>
          </fieldset>
          {after && before ? (
            <GoalVersionDiff after={after} assetCode={assetCode} before={before} />
          ) : null}
        </div>
      )}
    </Modal>
  );
}
