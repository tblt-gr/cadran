import {
  applyCategorizationRules,
  archiveCategorizationRule,
  createCategorizationRule,
  listAccounts,
  listCategorizationRules,
  previewCategorizationRules,
  updateCategorizationRule,
  type CategorizationRule,
  type CreateCategorizationRuleRequest,
  type UpdateCategorizationRuleRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  CategorizationRuleRequestError,
  categorizationRuleErrorKind,
  categorizationRuleRequestError,
} from './categorizationRuleError';
import { RuleEditor } from './rule-editor/RuleEditor';
import { RuleActivationDialog } from './rule-lifecycle/RuleActivationDialog';
import { RuleArchiveDialog } from './rule-lifecycle/RuleArchiveDialog';
import { RuleList } from './rule-list/RuleList';
import { RulePreviewDialog } from './rule-preview/RulePreviewDialog';
import styles from './CategorizationRulesPage.module.css';

type Editor = CategorizationRule | 'create' | null;

export function CategorizationRulesPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [editor, setEditor] = useState<Editor>(null);
  const [archiveTarget, setArchiveTarget] = useState<CategorizationRule | null>(null);
  const [activationTarget, setActivationTarget] = useState<CategorizationRule | null>(null);
  const [previewTarget, setPreviewTarget] = useState<CategorizationRule | null | undefined>(
    undefined,
  );
  const [previewStale, setPreviewStale] = useState(false);
  const [saved, setSaved] = useState<'applied' | 'saved' | null>(null);

  const accounts = useQuery({
    queryKey: ['accounts', 'rule-scope'],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const rules = useQuery({
    queryKey: ['categorization-rules'],
    queryFn: async ({ signal }) => {
      const result = await listCategorizationRules({
        ...authApiOptions(),
        query: { includeArchived: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    retry: false,
  });

  async function refresh() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['categorization-rules'] }),
      queryClient.invalidateQueries({ queryKey: ['transactions'] }),
    ]);
  }

  const save = useMutation({
    mutationFn: async (body: CreateCategorizationRuleRequest | UpdateCategorizationRuleRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateCategorizationRule({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateCategorizationRuleRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createCategorizationRule({
                ...authApiOptions(),
                body: body as CreateCategorizationRuleRequest,
              }),
            );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async (error) => {
      if (error instanceof CategorizationRuleRequestError && error.kind === 'stale')
        await refresh();
    },
    onSuccess: async () => {
      setEditor(null);
      setSaved('saved');
      await refresh();
    },
  });
  const archive = useMutation({
    mutationFn: async (rule: CategorizationRule) => {
      const result = await withCsrfRetry(() =>
        archiveCategorizationRule({
          ...authApiOptions(),
          path: { id: rule.id },
          body: { version: rule.version },
        }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async () => refresh(),
    onSuccess: async () => {
      setArchiveTarget(null);
      setSaved('saved');
      await refresh();
    },
  });
  const activation = useMutation({
    mutationFn: async (rule: CategorizationRule) => {
      const { id, ...body } = rule;
      const result = await withCsrfRetry(() =>
        updateCategorizationRule({
          ...authApiOptions(),
          path: { id },
          body: {
            accountScope: body.accountScope,
            active: !rule.active,
            conditions: body.conditions,
            effectiveFrom: body.effectiveFrom,
            effectiveTo: body.effectiveTo,
            label: body.label,
            priority: body.priority,
            targetAxes: body.targetAxes,
            targetCategoryId: body.targetCategoryId,
            targetCounterparty: body.targetCounterparty,
            version: body.version,
          },
        }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async () => refresh(),
    onSuccess: async () => {
      setActivationTarget(null);
      setSaved('saved');
      await refresh();
    },
  });
  const preview = useMutation({
    mutationFn: async ({ from, to }: { from: string; to: string }) => {
      const result = await withCsrfRetry(() =>
        previewCategorizationRules({
          ...authApiOptions(),
          body: { from, ruleId: previewTarget?.id ?? null, to },
        }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onSuccess: () => setPreviewStale(false),
  });
  const apply = useMutation({
    mutationFn: async (previewToken: string) => {
      const result = await withCsrfRetry(() =>
        applyCategorizationRules({ ...authApiOptions(), body: { previewToken } }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: (error) => {
      if (error instanceof CategorizationRuleRequestError && error.kind === 'stale') {
        setPreviewStale(true);
      }
    },
    onSuccess: async () => {
      setSaved('applied');
      await refresh();
    },
  });

  const unauthorized =
    rules.error instanceof CategorizationRuleRequestError && rules.error.kind === 'unauthorized';
  const list = rules.data?.items ?? [];
  return (
    <div className={styles.page}>
      <section aria-labelledby="rules-intro-title" className={styles.intro}>
        <div>
          <p>{t('categorizationRules.eyebrow')}</p>
          <h2 id="rules-intro-title">{t('categorizationRules.title')}</h2>
          <span>{t('categorizationRules.description')}</span>
        </div>
        <div className={styles.introActions}>
          <button
            className="secondary-action"
            onClick={() => {
              preview.reset();
              apply.reset();
              setPreviewStale(false);
              setPreviewTarget(null);
            }}
            type="button"
          >
            {t('categorizationRules.preview.action')}
          </button>
          <button
            className="primary-action"
            onClick={() => {
              save.reset();
              setEditor('create');
            }}
            type="button"
          >
            {t('categorizationRules.add')}
          </button>
        </div>
      </section>
      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>
          {t(
            saved === 'applied'
              ? 'categorizationRules.toasts.applied'
              : 'categorizationRules.toasts.saved',
          )}
        </Toast>
      ) : null}
      {editor ? (
        <RuleEditor
          accounts={accounts.data?.items ?? []}
          close={() => {
            setEditor(null);
            save.reset();
          }}
          onSubmit={(body) => save.mutate(body)}
          pending={save.isPending}
          rule={editor === 'create' ? undefined : editor}
          submitError={categorizationRuleErrorKind(save.error, save.isError)}
        />
      ) : null}
      {archiveTarget ? (
        <Modal
          close={() => {
            setArchiveTarget(null);
            archive.reset();
          }}
          eyebrow={t('categorizationRules.archive.eyebrow')}
          title={t('categorizationRules.archive.title')}
        >
          <RuleArchiveDialog
            onCancel={() => setArchiveTarget(null)}
            onConfirm={() => archive.mutate(archiveTarget)}
            pending={archive.isPending}
            rule={archiveTarget}
            submitError={categorizationRuleErrorKind(archive.error, archive.isError)}
          />
        </Modal>
      ) : null}
      {activationTarget ? (
        <Modal
          close={() => {
            setActivationTarget(null);
            activation.reset();
          }}
          eyebrow={t('categorizationRules.lifecycle.eyebrow')}
          title={t(
            activationTarget.active
              ? 'categorizationRules.lifecycle.deactivate.title'
              : 'categorizationRules.lifecycle.activate.title',
          )}
        >
          <RuleActivationDialog
            onConfirm={() => activation.mutate(activationTarget)}
            pending={activation.isPending}
            rule={activationTarget}
            submitError={categorizationRuleErrorKind(activation.error, activation.isError)}
          />
        </Modal>
      ) : null}
      {previewTarget !== undefined ? (
        <Modal
          close={() => {
            setPreviewTarget(undefined);
            preview.reset();
            apply.reset();
            setPreviewStale(false);
          }}
          eyebrow={t('categorizationRules.preview.eyebrow')}
          title={t('categorizationRules.preview.title')}
        >
          <RulePreviewDialog
            applyError={categorizationRuleErrorKind(apply.error, apply.isError)}
            applyPending={apply.isPending}
            onApply={apply.mutateAsync}
            onPreview={(from, to) => {
              apply.reset();
              setPreviewStale(false);
              preview.mutate({ from, to });
            }}
            preview={previewStale ? null : (preview.data ?? null)}
            previewError={categorizationRuleErrorKind(preview.error, preview.isError)}
            previewPending={preview.isPending}
            ruleLabel={previewTarget?.label}
          />
        </Modal>
      ) : null}
      {rules.isPending ? (
        <section className={`card ${styles.state}`} aria-busy="true" role="status">
          <h2>{t('categorizationRules.loading')}</h2>
        </section>
      ) : rules.isError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h2>
            {t(
              unauthorized
                ? 'categorizationRules.unauthorized.title'
                : 'categorizationRules.error.title',
            )}
          </h2>
          <p>
            {t(
              unauthorized
                ? 'categorizationRules.unauthorized.description'
                : 'categorizationRules.error.description',
            )}
          </p>
          {!unauthorized ? (
            <button className="secondary-action" onClick={() => void rules.refetch()} type="button">
              {t('foundation.retry')}
            </button>
          ) : null}
        </section>
      ) : list.length === 0 ? (
        <section className={`card ${styles.state}`}>
          <h2>{t('categorizationRules.empty.title')}</h2>
          <p>{t('categorizationRules.empty.description')}</p>
          <button className="primary-action" onClick={() => setEditor('create')} type="button">
            {t('categorizationRules.addFirst')}
          </button>
        </section>
      ) : (
        <RuleList
          onArchive={(rule) => {
            archive.reset();
            setArchiveTarget(rule);
          }}
          onEdit={(rule) => {
            save.reset();
            setEditor(rule);
          }}
          onPreview={(rule) => {
            preview.reset();
            apply.reset();
            setPreviewStale(false);
            setPreviewTarget(rule);
          }}
          onToggle={(rule) => {
            activation.reset();
            setActivationTarget(rule);
          }}
          rules={list}
        />
      )}
    </div>
  );
}
