import type {
  GoalDetail,
  GoalMetric,
  GoalPeriodicity,
  GoalValueType,
  GoalVersion,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { isStockMetric } from '@/features/goals/goalFormFields';
import styles from './GoalDetail.module.css';

function RecordedVersion({
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
  const value = (raw: string | null | undefined) =>
    raw == null
      ? t('goals.noValue')
      : version?.valueType === 'AMOUNT'
        ? formatAmount(raw, assetCode, i18n.language)
        : formatRatioPercentage(raw, i18n.language);
  const values = [
    [t('goals.effectiveFrom'), version?.effectiveFrom],
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
      <h4>
        {version ? (
          t('goals.detail.version', { version: version.versionNo })
        ) : (
          <SkeletonBlock className={styles.block} />
        )}
      </h4>
      {version?.versionNo === appliedVersion ? <p>{t('goals.detail.appliedVersion')}</p> : null}
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
    </li>
  );
}

export function GoalDetailVersions({
  goal,
  appliedVersion,
  periodicity,
  metric,
  valueType,
}: {
  goal: GoalDetail | null;
  appliedVersion: number;
  periodicity: GoalPeriodicity;
  metric: GoalMetric;
  valueType: GoalValueType;
}) {
  const { t } = useTranslation();
  const versions = goal
    ? [...goal.versions].sort(
        (a, b) => a.effectiveFrom.localeCompare(b.effectiveFrom) || a.versionNo - b.versionNo,
      )
    : [null];
  return (
    <section className={styles.section}>
      <h3>{t('goals.history.title')}</h3>
      {versions.length === 0 ? (
        <p>{t('goals.history.empty')}</p>
      ) : (
        <ol className={styles.revisions}>
          {versions.map((version) => (
            <RecordedVersion
              key={version?.versionNo ?? 'pending'}
              version={version}
              assetCode={goal?.assetCode ?? 'EUR'}
              appliedVersion={appliedVersion}
              showSchedule={
                (goal?.periodicity ?? periodicity) === 'YEARLY' &&
                !isStockMetric(goal?.metric ?? metric) &&
                (version?.valueType ?? valueType) === 'AMOUNT'
              }
            />
          ))}
        </ol>
      )}
    </section>
  );
}
