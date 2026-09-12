import type { CategorizationRule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

interface RuleRowProps {
  onArchive: (rule: CategorizationRule) => void;
  onEdit: (rule: CategorizationRule) => void;
  onToggle: (rule: CategorizationRule) => void;
  onPreview: (rule: CategorizationRule) => void;
  rule: CategorizationRule;
}

export function RuleRow({ onArchive, onEdit, onPreview, onToggle, rule }: RuleRowProps) {
  const { t } = useTranslation();
  const archived = rule.archivedAt !== null;
  const inactive = !rule.active;
  const period =
    rule.effectiveTo === null
      ? t('categorizationRules.list.since', { from: rule.effectiveFrom })
      : t('categorizationRules.list.fromTo', { from: rule.effectiveFrom, to: rule.effectiveTo });
  return (
    <tr>
      <td>{rule.priority}</td>
      <th scope="row">{rule.label}</th>
      <td>{period}</td>
      <td>
        {rule.accountScope.length === 0
          ? t('categorizationRules.list.allAccounts')
          : t('categorizationRules.list.accountCount', { count: rule.accountScope.length })}
      </td>
      <td>{rule.appliedCount}</td>
      <td>
        <StatusBadge tone={archived || inactive ? 'warning' : 'positive'}>
          {archived
            ? t('categorizationRules.status.archived')
            : inactive
              ? t(`categorizationRules.reasons.${rule.deactivatedReason ?? 'USER'}`)
              : t('categorizationRules.status.active')}
        </StatusBadge>
      </td>
      <td>
        <ActionMenu
          items={[
            {
              disabled: archived,
              icon: 'edit',
              id: 'edit',
              label: t('categorizationRules.list.editFor', { label: rule.label }),
              onSelect: () => onEdit(rule),
              text: t('categorizationRules.list.edit'),
            },
            {
              disabled: archived,
              icon: 'rules',
              id: 'preview',
              label: t('categorizationRules.list.previewFor', { label: rule.label }),
              onSelect: () => onPreview(rule),
              text: t('categorizationRules.list.preview'),
            },
            {
              disabled: archived,
              icon: 'rules',
              id: 'toggle',
              label: t(
                rule.active
                  ? 'categorizationRules.list.deactivateFor'
                  : 'categorizationRules.list.activateFor',
                { label: rule.label },
              ),
              onSelect: () => onToggle(rule),
              text: t(
                rule.active
                  ? 'categorizationRules.list.deactivate'
                  : 'categorizationRules.list.activate',
              ),
            },
            {
              disabled: archived,
              icon: 'archive',
              id: 'archive',
              label: t('categorizationRules.list.archiveFor', { label: rule.label }),
              onSelect: () => onArchive(rule),
              text: t('categorizationRules.list.archive'),
            },
          ]}
          label={t('categorizationRules.list.openActions', { label: rule.label })}
        />
      </td>
    </tr>
  );
}
