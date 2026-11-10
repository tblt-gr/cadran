import { readGoal, type GoalEvaluation } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import { goalRequestError } from '@/features/goals/goalError';
import { GoalDetailIdentity } from './GoalDetailIdentity';
import { GoalDetailEvaluation } from './GoalDetailEvaluation';
import { GoalDetailVersions } from './GoalDetailVersions';
import styles from './GoalDetail.module.css';

interface Props {
  goalId: string;
  label: string;
  period: string;
  evaluation: GoalEvaluation;
  close: () => void;
}

/** Read-only details use the selected report evaluation, never the latest version's evaluation. */
export function GoalDetailModal({ goalId, label, period, evaluation, close }: Props) {
  const { t } = useTranslation();
  const detail = useQuery({
    queryKey: ['goal', goalId],
    queryFn: async ({ signal }) => {
      const result = await readGoal({ ...authApiOptions(), path: { id: goalId }, signal });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
  return (
    <Modal intent="detail" close={close} title={t('goals.panel.details', { label })}>
      <div
        className={styles.content}
        aria-busy={detail.isPending}
        aria-label={t('goals.detail.content')}
        role="region"
        tabIndex={0}
      >
        {detail.isPending ? <SkeletonStatus>{t('goals.detail.loading')}</SkeletonStatus> : null}
        {detail.isError && !detail.data ? (
          <div role="alert">
            <p>{t('goals.detail.error')}</p>
            <button
              className="secondary-action"
              onClick={() => void detail.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          </div>
        ) : (
          <>
            <GoalDetailEvaluation evaluation={evaluation} period={period} />
            <GoalDetailIdentity goal={detail.data ?? null} period={period} />
            <GoalDetailVersions
              goal={detail.data ?? null}
              appliedVersion={evaluation.versionNo}
              periodicity={evaluation.periodicity}
              metric={evaluation.metric}
              valueType={evaluation.valueType}
            />
          </>
        )}
      </div>
    </Modal>
  );
}
