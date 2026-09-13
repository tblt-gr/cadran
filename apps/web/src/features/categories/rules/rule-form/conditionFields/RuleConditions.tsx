import type { CategorizationRuleConditions } from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import formStyles from '@/features/categories/rules/rule-form/RuleForm.module.css';
import { AmountConditionFields } from './AmountConditionFields';
import { OtherConditionFields } from './OtherConditionFields';
import { TextConditionFields } from './TextConditionFields';
import styles from './RuleConditions.module.css';

interface RuleConditionsProps {
  amountInvalid: boolean;
  conditions: CategorizationRuleConditions;
  conditionsInvalid: boolean;
  onChange: (conditions: CategorizationRuleConditions) => void;
  showErrors: boolean;
  textInvalid: boolean;
}

/**
 * The three condition families as one accordion. Every family starts folded,
 * its summary states what it holds, and a family holding an invalid value
 * opens once the form tried to submit.
 */
export function RuleConditions({
  amountInvalid,
  conditions,
  conditionsInvalid,
  onChange,
  showErrors,
  textInvalid,
}: RuleConditionsProps) {
  const { t } = useTranslation();
  const titleId = useId();
  const { amount, direction, mcc, text } = conditions;
  const [textOpen, setTextOpen] = useState(false);
  const [amountOpen, setAmountOpen] = useState(false);
  const [otherOpen, setOtherOpen] = useState(false);
  const unused = t('categorizationRules.conditions.unused');

  const textSummary = text
    ? t('categorizationRules.conditions.textCount', { count: text.predicates.length })
    : unused;
  const amountBounds = amount
    ? [
        amount.min
          ? t('categorizationRules.conditions.amountMin', {
              asset: amount.assetCode,
              value: amount.min,
            })
          : null,
        amount.max
          ? t('categorizationRules.conditions.amountMax', {
              asset: amount.assetCode,
              value: amount.max,
            })
          : null,
      ].filter((value) => value !== null)
    : [];
  const amountSummary = amount
    ? amountBounds.join(' · ') || t('categorizationRules.conditions.amountUnbounded')
    : unused;
  const otherSummary =
    [
      mcc ? t('categorizationRules.conditions.mccValue', { value: mcc }) : null,
      direction ? t(`categorizationRules.directions.${direction}`) : null,
    ]
      .filter((value) => value !== null)
      .join(' · ') || unused;

  return (
    <section aria-labelledby={titleId} className={styles.conditions}>
      <div className={styles.heading}>
        <h3 id={titleId}>{t('categorizationRules.conditions.title')}</h3>
        <p className={formStyles.hint}>{t('categorizationRules.conditions.hint')}</p>
      </div>
      <div className={styles.families}>
        <div className={styles.family}>
          <Disclosure
            meta={textSummary}
            onToggle={setTextOpen}
            open={textOpen || (showErrors && textInvalid)}
            title={t('categorizationRules.conditions.textTitle')}
          >
            <TextConditionFields
              conditions={conditions}
              invalid={textInvalid}
              onChange={onChange}
              showErrors={showErrors}
            />
          </Disclosure>
        </div>
        <div className={styles.family}>
          <Disclosure
            meta={amountSummary}
            onToggle={setAmountOpen}
            open={amountOpen || (showErrors && amountInvalid)}
            title={t('categorizationRules.conditions.amountTitle')}
          >
            <AmountConditionFields conditions={conditions} onChange={onChange} />
          </Disclosure>
        </div>
        <div className={styles.family}>
          <Disclosure
            meta={otherSummary}
            onToggle={setOtherOpen}
            open={otherOpen}
            title={t('categorizationRules.conditions.otherTitle')}
          >
            <OtherConditionFields conditions={conditions} onChange={onChange} />
          </Disclosure>
        </div>
      </div>
      {showErrors && (conditionsInvalid || amountInvalid) ? (
        <p className={formStyles.alert} role="alert">
          {t(
            conditionsInvalid
              ? 'categorizationRules.validation.conditions'
              : 'categorizationRules.validation.amount',
          )}
        </p>
      ) : null}
    </section>
  );
}
