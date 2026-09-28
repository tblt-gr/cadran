import type { GoalSchedule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
import { formatGoalMonth } from '@/features/goals/goalMonth';
import styles from './GoalScheduleTable.module.css';

interface Props {
  assetCode: string;
  schedule: GoalSchedule | null | undefined;
}

/** Read-only monthly calendar of an annual goal: month, exact part and the API's own running total. */
export function GoalScheduleTable({ assetCode, schedule }: Props) {
  const { t, i18n } = useTranslation();
  if (!schedule) {
    return <p className={styles.empty}>{t('goals.schedule.table.empty')}</p>;
  }
  return (
    <table className={styles.table}>
      <caption className="sr-only">{t('goals.schedule.table.caption')}</caption>
      <thead>
        <tr>
          <th scope="col">{t('goals.schedule.table.month')}</th>
          <th scope="col">{t('goals.schedule.table.amount')}</th>
          <th scope="col">{t('goals.schedule.table.cumulative')}</th>
        </tr>
      </thead>
      <tbody>
        {schedule.parts.map((part) => (
          <tr key={part.month}>
            <th scope="row">{formatGoalMonth(part.month, i18n.language)}</th>
            <td>{formatAmount(part.amount, assetCode, i18n.language)}</td>
            <td>{formatAmount(part.cumulative, assetCode, i18n.language)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
