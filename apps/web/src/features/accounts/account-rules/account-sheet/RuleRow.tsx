import type { AccountRuleClaim, AccountRuleOverride, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { OverrideAction } from '@/features/accounts/account-rules/account-sheet/OverrideAction';
import { RuleHistory } from '@/features/accounts/account-rules/rule-history/RuleHistory';
import type { RuleLine } from '@/features/accounts/account-rules/ruleLayers';
import { SheetRow } from '@/features/accounts/account-rules/sheet-row/SheetRow';
import { RulePeriod } from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import styles from './AccountSheet.module.css';

interface RuleRowProps {
  focusAction: boolean;
  kind: ProductRuleKind;
  /** Replaces the rule's own name as the line label. */
  label?: string;
  line: RuleLine;
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
  /** Every claim this account ever recorded on this rule. */
  overrides: AccountRuleOverride[];
  /** Words in front of the value, such as "Taux garanti". */
  valueLead?: string;
}

/**
 * One rule of the account as a single line: its name, the value in force, since
 * when, and a warning when one changes how the figure is read. "Déroger" ends
 * the line; so does "Retirer la dérogation" when the value in force is the
 * account's own claim. Earlier values of the rule wait under a closed history.
 */
export function RuleRow({
  focusAction,
  kind,
  label,
  line,
  onOverride,
  onWithdraw,
  overrides,
  valueLead,
}: RuleRowProps) {
  const { t } = useTranslation();
  const name = label ?? t(`catalog.rules.kinds.${kind}`);
  const claim = line.claim;

  return (
    <SheetRow
      actions={
        <>
          <OverrideAction focusAction={focusAction} name={name} onClick={() => onOverride(kind)} />
          {claim ? (
            <button
              aria-label={t('accounts.rules.withdrawRule', { rule: name })}
              className={`secondary-action ${styles.action}`}
              onClick={() => onWithdraw(claim, kind)}
              type="button"
            >
              {t('accounts.rules.withdraw')}
            </button>
          ) : null}
        </>
      }
      alert={line.alert}
      below={
        <RuleHistory
          inForceId={claim?.overrideId ?? null}
          kind={kind}
          onWithdraw={onWithdraw}
          overrides={overrides}
        />
      }
      label={name}
      since={<RulePeriod validFrom={line.validFrom} validTo={line.validTo} />}
      value={
        <>
          {valueLead ? <span>{valueLead} </span> : null}
          {line.value}
        </>
      }
    />
  );
}
