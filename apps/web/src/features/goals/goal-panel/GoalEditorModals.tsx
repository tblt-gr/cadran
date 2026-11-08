import type { GoalDetail, UpdateGoalRequest } from '@cadran/api-client';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { useWorkspaceBaseCurrency } from '@/features/auth/useWorkspaceBaseCurrency';
import { goalErrorKind, goalErrorMismatch } from '@/features/goals/goalError';
import { GoalForm } from '@/features/goals/GoalForm';
import { GoalFormSkeleton } from '@/features/goals/GoalFormSkeleton';
import { CorrectionConfirmation } from '@/features/goals/history/CorrectionConfirmation';
import { GoalHistoryModal } from '@/features/goals/history/GoalHistoryModal';
import { GoalHistoryTableSkeleton } from '@/features/goals/history/GoalHistoryTableSkeleton';
import { ReferenceIncomeNote } from '@/features/goals/ReferenceIncomeNote';
import type { GoalEditor } from './useGoalEditor';

/** The goal form modal (creation or edition) with its correction confirmation step. */
function GoalFormModal({ editor }: { editor: GoalEditor }) {
  const { t } = useTranslation();
  const baseCurrency = useWorkspaceBaseCurrency();
  const { correction, creating, editing, editingGoal, latestDetail, preview, save } = editor;
  const formArea = useRef<HTMLDivElement>(null);
  const wasConfirming = useRef(false);
  // Leaving the confirmation step hands the focus back to the form it hid.
  useEffect(() => {
    if (correction === null && wasConfirming.current) {
      formArea.current?.querySelector<HTMLInputElement>('input[type="month"]')?.focus();
    }
    wasConfirming.current = correction !== null;
  }, [correction]);
  if (!creating && !editing) return null;

  return (
    <Modal
      intent={editing ? 'edit' : 'create'}
      close={editor.closeEditor}
      title={t(editing ? 'goals.editTitle' : 'goals.createTitle')}
    >
      {editor.detail.data ? <ReferenceIncomeNote goal={editor.detail.data as GoalDetail} /> : null}
      {editing && !latestDetail && editor.detail.isPending ? (
        <GoalFormSkeleton baseCurrency={baseCurrency} goal={editing} />
      ) : (
        <>
          <div hidden={correction !== null} ref={formArea}>
            <GoalForm
              baseCurrency={baseCurrency}
              goal={editing ? (editingGoal ?? editing) : undefined}
              initial={creating ?? undefined}
              key={editing ? `${editing.id}-${latestDetail ? 'detail' : 'summary'}` : 'create'}
              onSubmit={(body) => {
                if (editing && (body as UpdateGoalRequest).effectiveFrom! < editor.workspaceMonth) {
                  preview.mutate(body as UpdateGoalRequest);
                  return;
                }
                save.mutate(body);
              }}
              pending={save.isPending || preview.isPending}
              submitError={goalErrorKind(
                save.error ?? preview.error,
                save.isError || preview.isError,
              )}
              submitMismatch={goalErrorMismatch(save.error ?? preview.error)}
            />
          </div>
          {correction && editing ? (
            <CorrectionConfirmation
              assetCode={editing.assetCode}
              confirmed={editor.correctionConfirmed}
              error={save.isError ? (goalErrorKind(save.error, true) ?? 'network') : null}
              onBack={editor.backToForm}
              onConfirm={() => save.mutate(correction.body)}
              onConfirmedChange={editor.setCorrectionConfirmed}
              pending={save.isPending}
              preview={correction.preview}
            />
          ) : null}
        </>
      )}
    </Modal>
  );
}

/** The version history modal of one goal: skeleton while loading, an alert when it cannot load. */
function GoalHistoryDialog({ editor }: { editor: GoalEditor }) {
  const { t } = useTranslation();
  const { history, historyTarget } = editor;
  if (!historyTarget) return null;
  if (history.data) {
    return (
      <GoalHistoryModal
        assetCode={historyTarget.assetCode}
        close={editor.closeHistory}
        items={history.data.items}
      />
    );
  }
  return (
    <Modal intent="detail" close={editor.closeHistory} title={t('goals.history.title')}>
      {history.isPending ? (
        <GoalHistoryTableSkeleton />
      ) : (
        <p role="alert">{t('goals.panel.unavailable')}</p>
      )}
    </Modal>
  );
}

export function GoalEditorModals({ editor }: { editor: GoalEditor }) {
  return (
    <>
      <GoalFormModal editor={editor} />
      <GoalHistoryDialog editor={editor} />
    </>
  );
}
