import type { GoalCorrectionPreview } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAmount } from '@/lib/decimal';
import { formatGoalMonth } from '@/features/goals/goalMonth';
import styles from './GoalHistory.module.css';

export function CorrectionImpactTable({
  assetCode,
  preview,
}: {
  assetCode: string;
  preview: GoalCorrectionPreview;
}) {
  const { i18n, t } = useTranslation();
  const value = (amount: string | null) =>
    amount === null ? (
      <EmptyValue label={t('goals.notCalculable')} />
    ) : (
      formatAmount(amount, assetCode, i18n.language)
    );

  if (preview.months.length === 0) return <p>{t('goals.history.impactEmpty')}</p>;

  return (
    <div className={styles.tableWrap}>
      <table className={styles.table}>
        <caption>{t('goals.history.impactCaption')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('goals.history.month')}</th>
            <th scope="col">{t('goals.history.before')}</th>
            <th scope="col">{t('goals.history.after')}</th>
            <th scope="col">{t('goals.history.impactVersion')}</th>
          </tr>
        </thead>
        <tbody>
          {preview.months.map((item) => (
            <tr key={item.month}>
              <th scope="row">{formatGoalMonth(item.month, i18n.language)}</th>
              <td>
                {value(item.before.expected)} · {t(`goals.statuses.${item.before.status}`)}
              </td>
              <td>
                {value(item.after.expected)} · {t(`goals.statuses.${item.after.status}`)}
              </td>
              <td>
                {item.before.goalVersion === null || item.after.goalVersion === null ? (
                  <EmptyValue label={t('goals.notCalculable')} />
                ) : (
                  t('goals.history.impactVersionChange', {
                    before: item.before.goalVersion,
                    after: item.after.goalVersion,
                  })
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
