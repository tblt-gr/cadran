import type { AccountRuleLayer } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

/** Whether the value in force is the account's own claim or what it inherits, in words. */
export function RuleOriginBadge({ layer }: { layer: AccountRuleLayer }) {
  const { t } = useTranslation();

  return (
    <StatusBadge tone={layer === 'OVERRIDE' ? 'warning' : 'info'}>
      {t(
        layer === 'OVERRIDE' ? 'accounts.rules.origin.override' : 'accounts.rules.origin.inherited',
      )}
    </StatusBadge>
  );
}
