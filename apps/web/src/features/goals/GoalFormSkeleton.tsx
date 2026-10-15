import type { GoalSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { GoalForm } from './GoalForm';
import styles from './GoalsPage.module.css';

const noop = () => undefined;

/**
 * The goal's own form, frozen while its full history loads: the summary
 * already holds the values, so the final layout is drawn by the form itself.
 * Hidden from assistive technology so the status stays the one announcement.
 */
export function GoalFormSkeleton({
  baseCurrency,
  goal,
}: {
  baseCurrency: string;
  goal: GoalSummary;
}) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.frozen} data-testid="goal-form-skeleton">
      <SkeletonStatus>{t('goals.loading')}</SkeletonStatus>
      <fieldset aria-hidden="true" className={styles.frozen} disabled inert>
        <GoalForm
          baseCurrency={baseCurrency}
          goal={goal}
          onSubmit={noop}
          pending
          submitError={null}
          submitMismatch={null}
        />
      </fieldset>
    </div>
  );
}
