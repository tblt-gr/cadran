import type {
  AccountRuleClaim,
  AccountRuleLayer as LayerName,
  ProductRuleKind,
} from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountRuleLayer } from '@/features/accounts/account-rules/account-rule-layer/AccountRuleLayer';
import type { RuleLayerView } from '@/features/accounts/account-rules/ruleLayers';
import styles from './AccountRuleItem.module.css';

interface AccountRuleItemProps {
  effectiveLayer: LayerName;
  /** The rule whose override action takes focus when the reader comes back from a modal. */
  focusAction: boolean;
  kind: ProductRuleKind;
  layers: RuleLayerView[];
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
}

/**
 * One rule of the account, once per authority that states it.
 *
 * The layers are an ordered list in the API order (published, inherited,
 * local), each naming its origin in words, so the divergence stays legible and
 * the one in force is told apart by text and not by position.
 */
export function AccountRuleItem({
  effectiveLayer,
  focusAction,
  kind,
  layers,
  onOverride,
  onWithdraw,
}: AccountRuleItemProps) {
  const { t } = useTranslation();
  const headingId = useId();

  return (
    <li>
      <section aria-labelledby={headingId} className={styles.rule}>
        <header className={styles.heading}>
          <h4 id={headingId}>{t(`catalog.rules.kinds.${kind}`)}</h4>
          <button
            className="secondary-action"
            data-autofocus={focusAction || undefined}
            onClick={() => onOverride(kind)}
            type="button"
          >
            {t('accounts.rules.override')}
          </button>
        </header>
        <ol className={styles.layers}>
          {layers.map((layer) => (
            <AccountRuleLayer
              effectiveLayer={effectiveLayer}
              key={layer.layer}
              layer={layer}
              onWithdraw={(claim) => onWithdraw(claim, kind)}
            />
          ))}
        </ol>
      </section>
    </li>
  );
}
