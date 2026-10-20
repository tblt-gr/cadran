import type { AccountRuleClaim, AccountRuleLayer as LayerName } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { RuleClaim } from '@/features/accounts/account-rules/rule-claim/RuleClaim';
import { RuleLayerBadge } from '@/features/accounts/account-rules/rule-layer-badge/RuleLayerBadge';
import type { RuleLayerView } from '@/features/accounts/account-rules/ruleLayers';
import {
  RulePeriod,
  RuleSourceReading,
  RuleVerificationBadge,
} from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import styles from './AccountRuleLayer.module.css';

interface AccountRuleLayerProps {
  effectiveLayer: LayerName;
  layer: RuleLayerView;
  onWithdraw: (claim: AccountRuleClaim) => void;
}

/**
 * What one authority states for a rule, read in full: origin, value, validity,
 * measure, verification and source, with the local claim's reason and its
 * withdrawal. It only appears behind the rule's details button, so the main
 * reading path keeps the value in force alone.
 */
export function AccountRuleLayer({ effectiveLayer, layer, onWithdraw }: AccountRuleLayerProps) {
  const { t } = useTranslation();
  const inForce = layer.layer === effectiveLayer;

  return (
    <li className={inForce ? styles.inForce : styles.layer}>
      <dl className={styles.reading}>
        <div>
          <dt>{t('accounts.rules.fields.origin')}</dt>
          <dd>
            <RuleLayerBadge inForce={inForce} layer={layer.layer} />
          </dd>
        </div>
        <div>
          <dt>{t('accounts.rules.fields.value')}</dt>
          <dd className={styles.value}>{layer.value}</dd>
        </div>
        <div>
          <dt>{t('accounts.rules.fields.validity')}</dt>
          <dd>
            <RulePeriod validFrom={layer.validFrom} validTo={layer.validTo} />
          </dd>
        </div>
      </dl>
      {layer.claim ? <RuleClaim claim={layer.claim} onWithdraw={onWithdraw} /> : null}
      <dl className={styles.reading}>
        <div>
          <dt>{t('accounts.rules.fields.measure')}</dt>
          <dd>{layer.measure}</dd>
        </div>
        <div>
          <dt>{t('accounts.rules.fields.verification')}</dt>
          <dd>
            <RuleVerificationBadge verification={layer.verification} />
          </dd>
        </div>
        <div>
          <dt>{t('accounts.rules.fields.source')}</dt>
          <dd>
            <RuleSourceReading source={layer.source} />
          </dd>
        </div>
      </dl>
    </li>
  );
}
