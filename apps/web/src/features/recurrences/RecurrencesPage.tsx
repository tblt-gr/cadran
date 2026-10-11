import type { Recurrence, RecurrenceCandidate } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { ArchivedRecurrencesSection } from '@/features/recurrences/archived-recurrences-section/ArchivedRecurrencesSection';
import { CandidatesSection } from '@/features/recurrences/candidates-section/CandidatesSection';
import { ConfirmedRecurrencesSection } from '@/features/recurrences/confirmed-recurrences-section/ConfirmedRecurrencesSection';
import { OccurrenceTimeline } from '@/features/recurrences/occurrence-timeline/OccurrenceTimeline';
import { RecurrenceEditor } from '@/features/recurrences/recurrence-editor/RecurrenceEditor';
import { RecurrencesPageSkeleton } from './RecurrencesPageSkeleton';
import type { Editor } from './recurrenceEditorState';
import { useRecurrenceMutations } from './useRecurrenceMutations';
import { useRecurrencesListing } from './useRecurrencesListing';
import styles from './RecurrencesPage.module.css';

export function RecurrencesPage() {
  const { t } = useTranslation();
  const [editor, setEditor] = useState<Editor>(null);
  const [selected, setSelected] = useState<Recurrence | null>(null);

  const listing = useRecurrencesListing();
  const {
    accounts,
    active,
    archived,
    archivedRecurrences,
    candidates,
    hasError,
    loading,
    refetchAll,
    setShowArchived,
    showArchived,
  } = listing;

  const {
    archive,
    dismiss,
    dismissed,
    notice,
    restore,
    save,
    setNotice,
    submitErrorKind,
    undoDismissal,
  } = useRecurrenceMutations({
    editor,
    onArchived: () => setSelected(null),
    onRestored: () => setSelected(null),
    onSaved: () => setEditor(null),
  });

  function closeEditor() {
    setEditor(null);
    save.reset();
  }

  function confirmCandidate(candidate: RecurrenceCandidate) {
    setEditor(candidate);
  }

  return (
    <div className={styles.page}>
      <HeaderActions>
        <button className="primary-action" onClick={() => setEditor('create')} type="button">
          <Icon name="add" size={18} />
          {t('recurrences.add')}
        </button>
      </HeaderActions>
      <p className={styles.notice}>{t('recurrences.forecastNotice')}</p>
      {notice ? <Toast onDismiss={() => setNotice(null)}>{notice}</Toast> : null}
      {dismissed ? (
        <div className={styles.undo} role="status">
          <span>{t('recurrences.dismissalNotice')}</span>
          <button
            className="secondary-action"
            disabled={undoDismissal.isPending}
            onClick={() => undoDismissal.mutate(dismissed)}
            type="button"
          >
            {t('recurrences.undo')}
          </button>
        </div>
      ) : null}
      {editor ? (
        <RecurrenceEditor
          accounts={accounts.data ?? []}
          candidate={editor !== 'create' && !('id' in editor) ? editor : undefined}
          close={closeEditor}
          onSubmit={(body) => save.mutate(body)}
          pending={save.isPending}
          recurrence={editor !== 'create' && 'id' in editor ? editor : undefined}
          submitError={submitErrorKind ? t(`recurrences.errors.${submitErrorKind}`) : null}
        />
      ) : null}
      {loading ? (
        <RecurrencesPageSkeleton status={t('recurrences.loading')} />
      ) : hasError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h3>{t('recurrences.error.title')}</h3>
          <p>{t('recurrences.error.description')}</p>
          <button className="secondary-action" onClick={refetchAll} type="button">
            {t('foundation.retry')}
          </button>
        </section>
      ) : (
        <>
          <CandidatesSection
            candidates={candidates.data?.items ?? []}
            dismissPending={dismiss.isPending}
            onConfirm={confirmCandidate}
            onDismiss={(candidate) => dismiss.mutate(candidate)}
            partial={candidates.data?.partial ?? false}
          />
          <ConfirmedRecurrencesSection
            archivePending={archive.isPending}
            onArchive={(recurrence) => archive.mutate(recurrence)}
            onEdit={setEditor}
            onSelect={setSelected}
            onToggleArchived={() => setShowArchived((visible) => !visible)}
            recurrences={active}
            showArchived={showArchived}
          />
          {showArchived ? (
            <ArchivedRecurrencesSection
              isError={archivedRecurrences.isError}
              isPending={archivedRecurrences.isPending}
              onRestore={(recurrence) => restore.mutate(recurrence)}
              onRetry={() => void archivedRecurrences.refetch()}
              onSelect={setSelected}
              recurrences={archived}
              restorePending={restore.isPending}
            />
          ) : null}
          {selected ? (
            <OccurrenceTimeline close={() => setSelected(null)} recurrence={selected} />
          ) : null}
        </>
      )}
    </div>
  );
}
