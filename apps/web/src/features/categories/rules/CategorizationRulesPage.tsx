import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { categorizationRuleErrorKind } from './categorizationRuleError';
import { RuleAccountsNotice } from './rule-accounts/RuleAccountsNotice';
import { RuleEditor } from './rule-editor/RuleEditor';
import { useRuleEditor } from './rule-editor/useRuleEditor';
import { RuleActivationDialog } from './rule-lifecycle/RuleActivationDialog';
import { RuleArchiveDialog } from './rule-lifecycle/RuleArchiveDialog';
import { useRuleActivation } from './rule-lifecycle/useRuleActivation';
import { useRuleArchive } from './rule-lifecycle/useRuleArchive';
import { RulesSection } from './rule-list/RulesSection';
import { RulePreviewDialog } from './rule-preview/RulePreviewDialog';
import { useRulePreview } from './rule-preview/useRulePreview';
import { useCategorizationRules } from './useCategorizationRules';
import { useRuleAccounts } from './useRuleAccounts';
import styles from './CategorizationRulesPage.module.css';

export function CategorizationRulesPage() {
  const { t } = useTranslation();
  const [saved, setSaved] = useState<'applied' | 'saved' | null>(null);

  const accounts = useRuleAccounts();
  const rules = useCategorizationRules();
  const editorWorkflow = useRuleEditor(accounts.available, () => setSaved('saved'));
  const archiveWorkflow = useRuleArchive(() => setSaved('saved'));
  const activationWorkflow = useRuleActivation(() => setSaved('saved'));
  const previewWorkflow = useRulePreview(() => setSaved('applied'));

  const ruleLabelById = Object.fromEntries(rules.list.map((item) => [item.id, item.label]));

  return (
    <div className={styles.page}>
      <section aria-labelledby="rules-intro-title" className={styles.intro}>
        <div>
          <p>{t('categorizationRules.eyebrow')}</p>
          <div className={styles.titleRow}>
            <a
              aria-label={t('categorizationRules.backToCategories')}
              className={`icon-button ${styles.back}`}
              href="/transactions/categories"
              onClick={(event) => handleClientNavigation(event, '/transactions/categories')}
            >
              <Icon name="arrow-left" size={18} />
            </a>
            <h2 id="rules-intro-title">{t('categorizationRules.title')}</h2>
          </div>
          <span>{t('categorizationRules.description')}</span>
        </div>
        <div className={styles.introActions}>
          <button
            className="secondary-action"
            onClick={() => previewWorkflow.open(null)}
            type="button"
          >
            {t('categorizationRules.preview.action')}
          </button>
          <button
            className="primary-action"
            disabled={!accounts.available}
            onClick={() => editorWorkflow.open('create')}
            type="button"
          >
            {t('categorizationRules.add')}
          </button>
        </div>
      </section>

      <RuleAccountsNotice
        isError={accounts.query.isError}
        isPending={accounts.query.isPending}
        onRetry={() => void accounts.query.refetch()}
        unauthorized={accounts.unauthorized}
      />

      {saved ? (
        <Toast onDismiss={() => setSaved(null)}>
          {t(
            saved === 'applied'
              ? 'categorizationRules.toasts.applied'
              : 'categorizationRules.toasts.saved',
          )}
        </Toast>
      ) : null}

      {editorWorkflow.target && accounts.available ? (
        <RuleEditor
          accounts={accounts.query.data ?? []}
          close={editorWorkflow.close}
          onSubmit={(body) => editorWorkflow.save.mutate(body)}
          pending={editorWorkflow.save.isPending}
          rule={editorWorkflow.target === 'create' ? undefined : editorWorkflow.target}
          submitError={categorizationRuleErrorKind(
            editorWorkflow.save.error,
            editorWorkflow.save.isError,
          )}
        />
      ) : null}

      {archiveWorkflow.target ? (
        <Modal
          close={archiveWorkflow.close}
          eyebrow={t('categorizationRules.archive.eyebrow')}
          title={t('categorizationRules.archive.title')}
        >
          <RuleArchiveDialog
            onCancel={archiveWorkflow.close}
            onConfirm={() => archiveWorkflow.archive.mutate(archiveWorkflow.target!)}
            pending={archiveWorkflow.archive.isPending}
            rule={archiveWorkflow.target}
            submitError={categorizationRuleErrorKind(
              archiveWorkflow.archive.error,
              archiveWorkflow.archive.isError,
            )}
          />
        </Modal>
      ) : null}

      {activationWorkflow.target ? (
        <Modal
          close={activationWorkflow.close}
          eyebrow={t('categorizationRules.lifecycle.eyebrow')}
          title={t(
            activationWorkflow.target.active
              ? 'categorizationRules.lifecycle.deactivate.title'
              : 'categorizationRules.lifecycle.activate.title',
          )}
        >
          <RuleActivationDialog
            onConfirm={() => activationWorkflow.activation.mutate(activationWorkflow.target!)}
            pending={activationWorkflow.activation.isPending}
            rule={activationWorkflow.target}
            submitError={categorizationRuleErrorKind(
              activationWorkflow.activation.error,
              activationWorkflow.activation.isError,
            )}
          />
        </Modal>
      ) : null}

      {previewWorkflow.target !== undefined ? (
        <Modal
          close={previewWorkflow.close}
          eyebrow={t('categorizationRules.preview.eyebrow')}
          title={t('categorizationRules.preview.title')}
        >
          <RulePreviewDialog
            applyError={categorizationRuleErrorKind(
              previewWorkflow.apply.error,
              previewWorkflow.apply.isError,
            )}
            applyPending={previewWorkflow.apply.isPending}
            onApply={previewWorkflow.apply.mutateAsync}
            onPreview={previewWorkflow.requestPreview}
            preview={previewWorkflow.stale ? null : (previewWorkflow.preview.data ?? null)}
            previewError={categorizationRuleErrorKind(
              previewWorkflow.preview.error,
              previewWorkflow.preview.isError,
            )}
            previewPending={previewWorkflow.preview.isPending}
            ruleLabel={previewWorkflow.target?.label}
            ruleLabelById={ruleLabelById}
          />
        </Modal>
      ) : null}

      <RulesSection
        canEdit={accounts.available}
        onAddFirst={() => editorWorkflow.open('create')}
        onArchive={archiveWorkflow.open}
        onEdit={editorWorkflow.open}
        onPreview={previewWorkflow.open}
        onToggle={activationWorkflow.open}
        rules={rules}
      />
    </div>
  );
}
