import {
  archiveRecurrence,
  confirmRecurrence,
  detectRecurrenceCandidates,
  dismissRecurrenceCandidate,
  listAccounts,
  listRecurrences,
  restoreRecurrence,
  restoreRecurrenceCandidate,
  updateRecurrence,
  type CreateRecurrenceRequest,
  type Recurrence,
  type RecurrenceCandidate,
  type UpdateRecurrenceRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { CandidateCard } from '@/features/recurrences/candidate-list/CandidateCard';
import { OccurrenceTimeline } from '@/features/recurrences/occurrence-timeline/OccurrenceTimeline';
import { RecurrenceCard } from '@/features/recurrences/recurrence-card/RecurrenceCard';
import { RecurrenceEditor } from '@/features/recurrences/recurrence-editor/RecurrenceEditor';
import styles from './RecurrencesPage.module.css';

type Editor = 'create' | RecurrenceCandidate | Recurrence | null;

function failed(result: { response?: Response }) {
  return new Error(`recurrence:${result.response?.status ?? 0}`);
}

export function RecurrencesPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [editor, setEditor] = useState<Editor>(null);
  const [selected, setSelected] = useState<Recurrence | null>(null);
  const [dismissed, setDismissed] = useState<RecurrenceCandidate | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showArchived, setShowArchived] = useState(false);
  const candidates = useQuery({
    queryKey: ['recurrence-candidates'],
    queryFn: async ({ signal }) => {
      const result = await detectRecurrenceCandidates({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    retry: false,
  });
  const recurrences = useQuery({
    queryKey: ['recurrences', 'active'],
    queryFn: async ({ signal }) => {
      const result = await listRecurrences({
        ...authApiOptions(),
        query: { includeArchived: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    retry: false,
  });
  const archivedRecurrences = useQuery({
    enabled: showArchived,
    queryKey: ['recurrences', 'archived'],
    queryFn: async ({ signal }) => {
      const result = await listRecurrences({
        ...authApiOptions(),
        query: { includeArchived: true, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw failed(result);
      return {
        ...result.data,
        items: result.data.items.filter((recurrence) => recurrence.archivedAt !== null),
      };
    },
    retry: false,
  });
  const accounts = useQuery({
    queryKey: ['accounts', 'recurrences'],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data.items;
    },
    retry: false,
  });
  const save = useMutation({
    mutationFn: async (body: CreateRecurrenceRequest | UpdateRecurrenceRequest) => {
      const result =
        editor && editor !== 'create' && 'id' in editor
          ? await withCsrfRetry(() =>
              updateRecurrence({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateRecurrenceRequest,
              }),
            )
          : await withCsrfRetry(() =>
              confirmRecurrence({ ...authApiOptions(), body: body as CreateRecurrenceRequest }),
            );
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    onSuccess: async () => {
      setEditor(null);
      setNotice(t('recurrences.toasts.saved'));
      await queryClient.invalidateQueries({ queryKey: ['recurrences'] });
    },
  });
  const dismiss = useMutation({
    mutationFn: async (candidate: RecurrenceCandidate) => {
      const result = await withCsrfRetry(() =>
        dismissRecurrenceCandidate({
          ...authApiOptions(),
          body: { fingerprint: candidate.fingerprint },
        }),
      );
      if (!result.response?.ok) throw failed(result);
      return candidate;
    },
    onSuccess: async (candidate) => {
      setDismissed(candidate);
      setNotice(t('recurrences.toasts.dismissed'));
      await queryClient.invalidateQueries({ queryKey: ['recurrence-candidates'] });
    },
  });
  const undoDismissal = useMutation({
    mutationFn: async (candidate: RecurrenceCandidate) => {
      const result = await withCsrfRetry(() =>
        restoreRecurrenceCandidate({
          ...authApiOptions(),
          path: { fingerprint: candidate.fingerprint },
        }),
      );
      if (!result.response?.ok) throw failed(result);
    },
    onSuccess: async () => {
      setDismissed(null);
      setNotice(t('recurrences.toasts.restored'));
      await queryClient.invalidateQueries({ queryKey: ['recurrence-candidates'] });
    },
  });
  const restore = useMutation({
    mutationFn: async (recurrence: Recurrence) => {
      const result = await withCsrfRetry(() =>
        restoreRecurrence({
          ...authApiOptions(),
          path: { id: recurrence.id },
          body: { version: recurrence.version },
        }),
      );
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    onSuccess: async () => {
      setSelected(null);
      setNotice(t('recurrences.toasts.unarchived'));
      await queryClient.invalidateQueries({ queryKey: ['recurrences'] });
    },
  });
  const archive = useMutation({
    mutationFn: async (recurrence: Recurrence) => {
      const result = await withCsrfRetry(() =>
        archiveRecurrence({
          ...authApiOptions(),
          path: { id: recurrence.id },
          body: { version: recurrence.version },
        }),
      );
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    onSuccess: async () => {
      setSelected(null);
      setNotice(t('recurrences.toasts.archived'));
      await queryClient.invalidateQueries({ queryKey: ['recurrences'] });
    },
  });
  const hasError = candidates.isError || recurrences.isError || accounts.isError;
  const loading = candidates.isPending || recurrences.isPending || accounts.isPending;
  const active = recurrences.data?.items ?? [];
  const archived = archivedRecurrences.data?.items ?? [];

  return (
    <div className={styles.page}>
      <section aria-labelledby="recurrences-intro-title" className={styles.intro}>
        <div>
          <p>{t('recurrences.eyebrow')}</p>
          <div className={styles.titleRow}>
            <a
              aria-label={t('recurrences.backToTransactions')}
              className={`icon-button ${styles.back}`}
              href="/transactions"
              onClick={(event) => handleClientNavigation(event, '/transactions')}
            >
              <Icon name="arrow-left" size={18} />
            </a>
            <h2 id="recurrences-intro-title">{t('recurrences.title')}</h2>
          </div>
          <span>{t('recurrences.description')}</span>
        </div>
        <button className="primary-action" onClick={() => setEditor('create')} type="button">
          {t('recurrences.add')}
        </button>
      </section>
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
          close={() => {
            setEditor(null);
            save.reset();
          }}
          onSubmit={(body) => save.mutate(body)}
          pending={save.isPending}
          recurrence={editor !== 'create' && 'id' in editor ? editor : undefined}
          submitError={save.isError ? t('recurrences.errors.save') : null}
        />
      ) : null}
      {loading ? (
        <section aria-busy="true" className={`card ${styles.state}`} role="status">
          <h3>{t('recurrences.loading')}</h3>
        </section>
      ) : hasError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h3>{t('recurrences.error.title')}</h3>
          <p>{t('recurrences.error.description')}</p>
          <button
            className="secondary-action"
            onClick={() => {
              void candidates.refetch();
              void recurrences.refetch();
              void accounts.refetch();
            }}
            type="button"
          >
            {t('foundation.retry')}
          </button>
        </section>
      ) : (
        <>
          <section aria-labelledby="recurrence-candidates-title" className={styles.section}>
            <div className={styles.sectionHeading}>
              <div>
                <p>{t('recurrences.candidates.eyebrow')}</p>
                <h3 id="recurrence-candidates-title">{t('recurrences.candidates.title')}</h3>
              </div>
              {candidates.data?.partial ? (
                <span className={styles.partial}>{t('recurrences.candidates.partial')}</span>
              ) : null}
            </div>
            {candidates.data?.items.length ? (
              <div className={styles.candidates}>
                {candidates.data.items.map((candidate) => (
                  <CandidateCard
                    candidate={candidate}
                    key={candidate.fingerprint}
                    onConfirm={setEditor}
                    onDismiss={(candidate) => dismiss.mutate(candidate)}
                    pending={dismiss.isPending}
                  />
                ))}
              </div>
            ) : (
              <div className={`card ${styles.empty}`}>
                <h4>{t('recurrences.candidates.emptyTitle')}</h4>
                <p>{t('recurrences.candidates.emptyDescription')}</p>
              </div>
            )}
          </section>
          <section aria-labelledby="confirmed-recurrences-title" className={styles.section}>
            <div className={styles.sectionHeading}>
              <div>
                <p>{t('recurrences.confirmed.eyebrow')}</p>
                <h3 id="confirmed-recurrences-title">{t('recurrences.confirmed.title')}</h3>
              </div>
              <button
                aria-controls="archived-recurrences"
                aria-expanded={showArchived}
                className="secondary-action"
                onClick={() => setShowArchived((visible) => !visible)}
                type="button"
              >
                {t(
                  showArchived
                    ? 'recurrences.confirmed.hideArchived'
                    : 'recurrences.confirmed.showArchived',
                )}
              </button>
            </div>
            {active.length === 0 ? (
              <div className={`card ${styles.empty}`}>
                <h4>{t('recurrences.confirmed.emptyTitle')}</h4>
                <p>{t('recurrences.confirmed.emptyDescription')}</p>
              </div>
            ) : (
              <div className={styles.schedules}>
                {active.map((recurrence) => (
                  <RecurrenceCard
                    actions={
                      <>
                        <button
                          className="secondary-action"
                          onClick={() => setSelected(recurrence)}
                          type="button"
                        >
                          {t('recurrences.confirmed.occurrences')}
                        </button>
                        <button
                          className="secondary-action"
                          onClick={() => setEditor(recurrence)}
                          type="button"
                        >
                          {t('recurrences.confirmed.edit')}
                        </button>
                        <button
                          className="secondary-action"
                          disabled={archive.isPending}
                          onClick={() => archive.mutate(recurrence)}
                          type="button"
                        >
                          {t('recurrences.confirmed.archive')}
                        </button>
                      </>
                    }
                    key={recurrence.id}
                    recurrence={recurrence}
                  />
                ))}
              </div>
            )}
          </section>
          {showArchived ? (
            <section
              aria-labelledby="archived-recurrences-title"
              className={styles.section}
              id="archived-recurrences"
            >
              <div className={styles.sectionHeading}>
                <div>
                  <p>{t('recurrences.confirmed.eyebrow')}</p>
                  <h3 id="archived-recurrences-title">{t('recurrences.confirmed.archived')}</h3>
                </div>
              </div>
              {archivedRecurrences.isPending ? (
                <div className={`card ${styles.state}`} role="status">
                  <h4>{t('recurrences.loading')}</h4>
                </div>
              ) : archivedRecurrences.isError ? (
                <div className={`card ${styles.state}`} role="alert">
                  <h4>{t('recurrences.error.title')}</h4>
                  <button
                    className="secondary-action"
                    onClick={() => void archivedRecurrences.refetch()}
                    type="button"
                  >
                    {t('foundation.retry')}
                  </button>
                </div>
              ) : archived.length === 0 ? (
                <div className={`card ${styles.empty}`}>
                  <h4>{t('recurrences.confirmed.emptyArchivedTitle')}</h4>
                  <p>{t('recurrences.confirmed.emptyArchivedDescription')}</p>
                </div>
              ) : (
                <div className={styles.schedules}>
                  {archived.map((recurrence) => (
                    <RecurrenceCard
                      actions={
                        <>
                          <button
                            className="secondary-action"
                            onClick={() => setSelected(recurrence)}
                            type="button"
                          >
                            {t('recurrences.confirmed.occurrences')}
                          </button>
                          <button
                            className="secondary-action"
                            disabled={restore.isPending}
                            onClick={() => restore.mutate(recurrence)}
                            type="button"
                          >
                            {t('recurrences.confirmed.restore')}
                          </button>
                        </>
                      }
                      key={recurrence.id}
                      recurrence={recurrence}
                    />
                  ))}
                </div>
              )}
            </section>
          ) : null}
          {selected ? (
            <OccurrenceTimeline close={() => setSelected(null)} recurrence={selected} />
          ) : null}
        </>
      )}
    </div>
  );
}
