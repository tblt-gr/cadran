import type { ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';

interface RuleActionsProps {
  /** Loading placeholder: the menu keeps its place and is inert. */
  disabled?: boolean;
  /** Takes focus on mount, when the reader comes back from a modal opened from this menu. */
  focusAction?: boolean;
  kind?: ProductRuleKind;
  /** The rule's name, for the accessible names "Actions : <règle>", "Déroger : <règle>". */
  name?: string;
  onOverride?: (kind: ProductRuleKind) => void;
}

/**
 * The three-dot menu that ends a rule's line. It holds the one entry point to
 * override the rule; withdrawing a claim is the history entry's own button.
 */
export function RuleActions({
  disabled = false,
  focusAction = false,
  kind,
  name = '',
  onOverride,
}: RuleActionsProps) {
  const { t } = useTranslation();

  return (
    <ActionMenu
      autoFocus={focusAction}
      disabled={disabled}
      items={[
        {
          icon: 'edit',
          id: 'override',
          label: t('accounts.rules.overrideRule', { rule: name }),
          onSelect: () => (kind === undefined ? undefined : onOverride?.(kind)),
          text: t('accounts.rules.override'),
        },
      ]}
      label={
        name === ''
          ? t('accounts.rules.actionsPending')
          : t('accounts.rules.actions', { rule: name })
      }
    />
  );
}
