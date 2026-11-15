import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import type {
  Account,
  CreateTransactionRequest,
  Transaction,
  UpdateTransactionRequest,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SplitAxes } from '@/features/transactions/split-editor/split-axes/SplitAxes';
import type { TransactionErrorKind } from '@/features/transactions/transactionError';
import { AdvancedTransactionFields } from './advanced-fields/AdvancedTransactionFields';
import { TransactionMainFields } from './main-fields/TransactionMainFields';
import { SplitField } from './split-field/SplitField';
import { TransactionCategoryField } from './transaction-category-field/TransactionCategoryField';
import { TransactionSubmitError } from '@/features/transactions/submit-error/TransactionSubmitError';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useTransactionFormState, type TransactionFormDefaults } from './useTransactionFormState';
import styles from './TransactionForm.module.css';

export type { TransactionFormDefaults };

interface TransactionFormProps {
  accounts: Account[];
  defaults?: TransactionFormDefaults;
  defaultAccountId?: string;
  onSubmit: (body: CreateTransactionRequest | UpdateTransactionRequest) => void;
  pending: boolean;
  submitError: TransactionErrorKind | null;
  submitErrorDetail?: string | null;
  transaction?: Transaction;
}

/**
 * The fields of nearly every entry - account, date, amount, nature, label, payment
 * method and category - come first; the rest is folded under "advanced fields".
 */
export function TransactionForm({
  accounts,
  defaults,
  defaultAccountId,
  onSubmit,
  pending,
  submitError,
  submitErrorDetail,
  transaction,
}: TransactionFormProps) {
  const { t } = useTranslation();
  const {
    advancedOpen,
    amountAssetCode,
    balanceAfter,
    categoryDefaultAxes,
    direction,
    editing,
    errors,
    markAccountChosen,
    preferredType,
    rawLabelLocked,
    resolved,
    savedCategory,
    set,
    setAdvancedOpen,
    setValues,
    showErrors,
    signedAmount,
    stateLocked,
    submit,
    upcoming,
    values,
  } = useTransactionFormState({ accounts, defaultAccountId, defaults, onSubmit, transaction });

  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <TransactionSubmitError
          accountId={resolved.accountId}
          className={styles.alert}
          kind={submitError}
          detail={submitErrorDetail}
        />
      ) : null}

      <FormGrid className={styles.essential}>
        <TransactionMainFields
          accounts={accounts}
          amountAssetCode={amountAssetCode}
          balanceAfter={balanceAfter}
          direction={direction}
          editing={editing}
          errors={errors}
          onAccountChosen={markAccountChosen}
          onChange={set}
          rawLabelLocked={rawLabelLocked}
          resolvedAccountId={resolved.accountId}
          showErrors={showErrors}
          upcoming={upcoming}
          values={values}
        />

        {values.splitMode ? (
          <div className={styles.splitNotice}>
            <span>{t('transactions.fields.category')}</span>
          </div>
        ) : (
          <TransactionCategoryField
            error={
              errors.categoryId && values.categoryType !== null
                ? t(`transactions.validation.categoryMismatch.${values.categoryType}`)
                : null
            }
            onChange={(categoryId, categoryType, categoryDefaultAxes) =>
              setValues((current) => ({
                ...current,
                // An override made for the previous category no longer describes this one.
                analyticAxes: categoryId === current.categoryId ? current.analyticAxes : null,
                categoryDefaultAxes,
                categoryId,
                categoryType,
              }))
            }
            preferredType={preferredType}
            savedCategory={savedCategory}
            value={values.categoryId}
          />
        )}

        <div data-span="full">
          <SplitField
            assetCode={amountAssetCode}
            direction={direction}
            onChangeSplitMode={(splitMode) => set('splitMode', splitMode)}
            onChangeSplits={(splits) => set('splits', splits)}
            preferredType={preferredType}
            showErrors={showErrors}
            splitMode={values.splitMode}
            splits={values.splits}
            total={signedAmount}
          />
        </div>

        {!values.splitMode && values.categoryId !== '' && values.categoryType === 'EXPENSE' ? (
          <div data-span="full">
            <SplitAxes
              axes={values.analyticAxes}
              compact={false}
              defaultAxes={categoryDefaultAxes}
              legend={t('transactions.form.axesLegend')}
              onChange={(analyticAxes) => set('analyticAxes', analyticAxes)}
            />
          </div>
        ) : null}

        <AdvancedTransactionFields
          errors={errors}
          onChange={set}
          onToggle={setAdvancedOpen}
          open={advancedOpen}
          rejectable={editing && transaction.state === 'PENDING'}
          showErrors={showErrors}

          stateLockedHint={stateLocked ? t('transactions.form.stateLocked') : null}
          statePlanned={upcoming}
          values={resolved}
        />
      </FormGrid>

      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'transactions.form.saving' : 'transactions.form.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
