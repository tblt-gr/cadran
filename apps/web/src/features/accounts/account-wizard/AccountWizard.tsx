import type { CreateAccountRequest, Product } from '@cadran/api-client';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountForm } from '@/features/accounts/account-form/AccountForm';
import type { AccountFormValues } from '@/features/accounts/account-form/accountFormValues';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { todayInBrowser } from '@/lib/businessDay';
import { ProductStep } from './product-step/ProductStep';
import { ReviewStep } from './review-step/ReviewStep';
import { useProductOptions } from './useProductOptions';
import { WizardSteps, type WizardStep } from './wizard-steps/WizardSteps';
import styles from './AccountWizard.module.css';

interface AccountWizardProps {
  onCancel: () => void;
  onCreate: (body: CreateAccountRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * Creating an account: pick the model, describe the account, then review.
 *
 * The review exists because a product-backed account inherits a kind, a
 * valuation mode and a set of dated rules the user never typed. Confirming is
 * the moment they see what the catalogue says, with each figure's period and
 * source, before anything is written.
 */
export function AccountWizard({ onCancel, onCreate, pending, submitError }: AccountWizardProps) {
  const { t } = useTranslation();
  const [asOf] = useState(todayInBrowser);
  const [step, setStep] = useState<WizardStep>('product');
  const [product, setProduct] = useState<Product | null>(null);
  // The typed fields and the validated body are kept apart: a half-filled form
  // has no request shape, but stepping back must still find it as it was left.
  const [values, setValues] = useState<AccountFormValues | null>(null);
  const [draft, setDraft] = useState<CreateAccountRequest | null>(null);
  const products = useProductOptions(asOf);
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

  function chooseProduct(chosen: Product | null) {
    if (chosen?.code === product?.code) {
      return;
    }

    setProduct(chosen);
    // The kind and the valuation mode are the product's to declare, so a draft
    // written against another model is dropped rather than partly reused.
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
            onCancel={onCancel}
            onContinue={() => setStep('details')}
            onSelect={chooseProduct}
            products={products}
            selected={product}
          />
        ) : null}

        {step === 'details' ? (
          <>
            <p className={styles.chosen}>
              {product
                ? t('accounts.wizard.details.fromProduct', { product: product.displayName })
                : t('accounts.wizard.details.withoutProduct')}
            </p>
            <AccountForm
              defaults={values}
              key={product?.code ?? 'none'}
              onCancel={(current) => {
                setValues(current);
                setStep('product');
              }}
              onSubmit={(body, current) => {
                setValues(current);
                setDraft(body as CreateAccountRequest);
                setStep('review');
              }}
              pending={false}
              product={product}
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
            pending={pending}
            product={product}
            submitError={submitError}
          />
        ) : null}
      </div>
    </div>
  );
}
