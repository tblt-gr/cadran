import type { CreateAccountRequest } from '@cadran/api-client';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { type AccountOrigin, sameOrigin } from '@/features/accounts/account-form/accountOrigin';
import { AccountForm } from '@/features/accounts/account-form/AccountForm';
import type { AccountFormValues } from '@/features/accounts/account-form/accountFormValues';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { todayInBrowser } from '@/lib/businessDay';
import { ProductStep } from './product-step/ProductStep';
import { ReviewStep } from './review-step/ReviewStep';
import { useProductOptions } from './useProductOptions';
import { useTemplateOptions } from './useTemplateOptions';
import { WizardSteps, type WizardStep } from './wizard-steps/WizardSteps';
import styles from './AccountWizard.module.css';

interface AccountWizardProps {
  onCancel: () => void;
  onCreate: (body: CreateAccountRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * Creating an account: pick what it follows, describe the account, then review.
 *
 * The review exists because an account backed by a product or a template
 * inherits a kind, a valuation mode and a set of dated rules the user never
 * typed. Confirming is the moment they see what that origin says, with each
 * figure's period and — for a catalogue product — its official source,
 * before anything is written.
 */
export function AccountWizard({ onCreate, pending, submitError }: AccountWizardProps) {
  const { t } = useTranslation();
  const [asOf] = useState(todayInBrowser);
  const [step, setStep] = useState<WizardStep>('product');
  const [origin, setOrigin] = useState<AccountOrigin>(null);
  // The typed fields and the validated body are kept apart: a half-filled form
  // has no request shape, but stepping back must still find it as it was left.
  const [values, setValues] = useState<AccountFormValues | null>(null);
  const [draft, setDraft] = useState<CreateAccountRequest | null>(null);
  const products = useProductOptions(asOf);
  const templates = useTemplateOptions();
  const stepContainer = useRef<HTMLDivElement>(null);
  const firstRender = useRef(true);

  // Replacing the step swaps the whole dialog body, so focus is moved to the
  // new step rather than dropped on the document. The dialog owns the initial
  // focus, which is why the first render is skipped.
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;

      return;
    }

    stepContainer.current?.focus();
  }, [step]);

  function chooseOrigin(chosen: AccountOrigin) {
    if (sameOrigin(chosen, origin)) {
      return;
    }

    setOrigin(chosen);
    // The kind and the valuation mode are the origin's to declare, so a draft
    // written against another one is dropped rather than partly reused.
    setValues(null);
    setDraft(null);
  }

  return (
    <div className={styles.wizard}>
      <WizardSteps current={step} />

      <div className={styles.step} ref={stepContainer} tabIndex={-1}>
        {step === 'product' ? (
          <ProductStep
            asOf={asOf}
            onContinue={() => setStep('details')}
            onSelect={chooseOrigin}
            products={products}
            selected={origin}
            templates={templates}
          />
        ) : null}

        {step === 'details' ? (
          <>
            <p className={styles.chosen}>
              {origin?.type === 'product'
                ? t('accounts.wizard.details.fromProduct', { product: origin.product.displayName })
                : origin?.type === 'template'
                  ? t('accounts.wizard.details.fromTemplate', { template: origin.template.name })
                  : t('accounts.wizard.details.withoutProduct')}
            </p>
            <AccountForm
              defaults={values}
              key={
                origin?.type === 'product' ? origin.product.code : (origin?.template.id ?? 'none')
              }
              onCancel={(current) => {
                setValues(current);
                setStep('product');
              }}
              onSubmit={(body, current) => {
                setValues(current);
                setDraft(body as CreateAccountRequest);
                setStep('review');
              }}
              origin={origin}
              pending={false}
              submitError={null}
              submitLabel="continue"
            />
          </>
        ) : null}

        {step === 'review' && draft ? (
          <ReviewStep
            draft={draft}
            onBack={() => setStep('details')}
            onConfirm={() => onCreate(draft)}
            origin={origin}
            pending={pending}
            submitError={submitError}
          />
        ) : null}
      </div>
    </div>
  );
}
