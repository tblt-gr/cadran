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
  /** Present only when a claim can be withdrawn; absent, the entry does not exist. */
  onWithdraw?: () => void;
}

/** The three-dot menu that ends a rule's line: override it and, when one stands, withdraw the claim. */
export function RuleActions({
  disabled = false,
  focusAction = false,
  kind,
  name = '',
  onOverride,
  onWithdraw,
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
        ...(onWithdraw
          ? [
              {
                icon: 'delete' as const,
                id: 'withdraw',
                label: t('accounts.rules.withdrawRule', { rule: name }),
                onSelect: onWithdraw,
                text: t('accounts.rules.withdraw'),
              },
            ]
          : []),
      ]}
      label={
        name === ''
          ? t('accounts.rules.actionsPending')
          : t('accounts.rules.actions', { rule: name })
      }
    />
  );
}
