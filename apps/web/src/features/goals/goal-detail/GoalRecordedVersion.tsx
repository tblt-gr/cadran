import type { GoalVersion } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { longMonthYear } from '@/features/goals/goal-panel/goalRecurrence';
import styles from './GoalDetail.module.css';

export function GoalRecordedVersion({
  version,
  assetCode,
  appliedVersion,
  showSchedule,
}: {
  version: GoalVersion | null;
  assetCode: string;
  appliedVersion: number;
  showSchedule: boolean;
}) {
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(version === null || version.versionNo === appliedVersion);
  const value = (raw: string | null | undefined) =>
    raw == null
      ? t('goals.noValue')
      : version?.valueType === 'AMOUNT'
        ? formatAmount(raw, assetCode, i18n.language)
        : formatRatioPercentage(raw, i18n.language);
  const values = [
    [
      t('goals.history.fields.valueType'),
      version ? t(`goals.history.valueTypes.${version.valueType}`) : null,
    ],
    [
      t('goals.detail.target'),
      version ? value(version.valueType === 'AMOUNT' ? version.targetAmount : version.ratio) : null,
    ],
    [
      t('goals.tolerance'),
      version
        ? version.valueType === 'AMOUNT'
          ? value(version.tolerance)
          : t('goals.tolerancePointValue', {
              value: formatRatioPercentage(version.tolerance, i18n.language).replace(/\s*%$/, ''),
            })
        : null,
    ],
  ];
  return (
    <li className={styles.revision}>
      <Disclosure
        disabled={version === null}
        meta={
          <span className={styles.effectiveDate}>
            {version === null ? (
              <SkeletonBlock className={styles.block} />
            ) : (
              t('goals.detail.recordedFrom', {
                period: longMonthYear(version.effectiveFrom, i18n.language),
              })
            )}
          </span>
        }
        onToggle={setOpen}
        open={open}
        spread
        title={
          <span className={styles.versionSummary}>
            <span aria-level={4} role="heading" className={styles.versionTitle}>
              {version ? (
                t('goals.detail.version', { version: version.versionNo })
              ) : (
                <SkeletonBlock className={styles.block} />
              )}
            </span>
            {version?.versionNo === appliedVersion ? (
              <span className={styles.appliedVersion}>{t('goals.detail.appliedVersion')}</span>
            ) : null}
          </span>
        }
      >
        <div className={styles.versionBody}>
          <dl className={styles.values}>
            {values.map(([label, shown]) => (
              <div key={label}>
                <dt>{label}</dt>
                <dd>{version === null ? <SkeletonBlock className={styles.block} /> : shown}</dd>
              </div>
            ))}
          </dl>
          {showSchedule ? (
            <>
              <h5>{t('goals.schedule.legend')}</h5>
              <p>
                {version === null ? (
                  <SkeletonBlock className={styles.block} />
                ) : version.schedule ? (
                  t(
                    version.schedule.mode === 'LINEAR'
                      ? 'goals.schedule.modeLinear'
                      : 'goals.schedule.modeCustom',
                  )
                ) : (
                  t('goals.schedule.table.empty')
                )}
              </p>
              <table className={styles.schedule}>
                <caption>{t('goals.schedule.table.caption')}</caption>
                <thead>
                  <tr>
                    <th scope="col">{t('goals.schedule.table.month')}</th>
                    <th scope="col">{t('goals.schedule.table.amount')}</th>
                    <th scope="col">{t('goals.schedule.table.cumulative')}</th>
                  </tr>
                </thead>
                <tbody>
                  {(version === null
                    ? Array.from({ length: 12 }, () => null)
                    : (version.schedule?.parts ?? [])
                  ).map((part, index) => (
                    <tr key={part?.month ?? index}>
                      <th scope="row">
                        {part ? part.month : <SkeletonBlock className={styles.block} />}
                      </th>
                      <td>
                        {part ? (
                          formatAmount(part.amount, assetCode, i18n.language)
                        ) : (
                          <SkeletonBlock className={styles.block} />
                        )}
                      </td>
                      <td>
                        {part ? (
                          formatAmount(part.cumulative, assetCode, i18n.language)
                        ) : (
                          <SkeletonBlock className={styles.block} />
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          ) : null}
        </div>
      </Disclosure>
    </li>
  );
}
