<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Reconciliation\ReviewReason;

final readonly class Transaction
{
    public const int MAX_RAW_LABEL_LENGTH = 140;
    public const int MAX_COUNTERPARTY_LENGTH = 80;
    public const int MAX_NOTE_LENGTH = 500;
    public const int MAX_BANK_REFERENCE_LENGTH = 64;
    public const int MAX_SPLITS = 20;

    /** @param list<TransactionSplit> $splits */
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        public AssetAmount $amount,
        public ?AssetAmount $originalAmount,
        public ?DecimalValue $exchangeRate,
        public TransactionState $state,
        public TransactionNature $nature,
        public TransactionSource $source,
        public ?string $sourceRef,
        public \DateTimeImmutable $bookedOn,
        public ?\DateTimeImmutable $valueOn,
        public ?\DateTimeImmutable $authorizedOn,
        public string $rawLabel,
        public ?string $counterparty,
        public ?string $note,
        public ?PaymentMethod $paymentMethod,
        public ?string $mcc,
        public ?string $maskedCard,
        public ?string $bankReference,
        public array $splits,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $voidedAt,
        public ?string $lastEditorId,
        public ?ReviewReason $reviewReason = null,
    ) {
        self::identifier($id, 'transaction');
        self::identifier($accountId, 'account');
        if (null !== $lastEditorId) {
            self::identifier($lastEditorId, 'editor');
        }
        if (0 === $amount->value->compareTo(DecimalValue::zero())) {
            throw new InvalidTransaction('A transaction amount cannot be zero.');
        }
        self::assertSign($amount, $nature);
        self::requiredText($rawLabel, self::MAX_RAW_LABEL_LENGTH, 'raw label');
        self::optionalText($counterparty, self::MAX_COUNTERPARTY_LENGTH, 'counterparty');
        self::optionalText($note, self::MAX_NOTE_LENGTH, 'note');
        self::optionalText($bankReference, self::MAX_BANK_REFERENCE_LENGTH, 'bank reference');
        self::optionalText($sourceRef, 128, 'source reference');
        self::digits($mcc, 'merchant category code');
        self::digits($maskedCard, 'masked card');
        if (null !== $reviewReason && TransactionState::PENDING !== $state) {
            // A review reason is the reason a movement is *not* booked yet. On
            // any other state it would advertise a decision already taken.
            throw new InvalidTransaction('Only a pending transaction awaits reconciliation review.');
        }
        if ((TransactionState::VOIDED === $state) !== (null !== $voidedAt)) {
            throw new InvalidTransaction('Only a voided transaction carries a voiding timestamp.');
        }
        if (null !== $authorizedOn && $authorizedOn > $bookedOn) {
            throw new InvalidTransaction('The authorization date cannot be after the booked date.');
        }
        if (null !== $valueOn && abs((int) $bookedOn->diff($valueOn)->format('%r%a')) > 90) {
            throw new InvalidTransaction('The value date must stay within 90 days of the booked date.');
        }
        if (null !== $originalAmount) {
            if (null === $exchangeRate || $originalAmount->asset->toString() === $amount->asset->toString()
                || $exchangeRate->compareTo(DecimalValue::zero()) <= 0) {
                throw new InvalidTransaction('An original amount requires another asset and a positive exchange rate.');
            }
        } elseif (null !== $exchangeRate) {
            throw new InvalidTransaction('An exchange rate requires an original amount.');
        }
        self::assertSplits($id, $workspace, $amount, $splits);
        if ($version < 1 || $updatedAt < $createdAt) {
            throw new InvalidTransaction('A transaction version and timestamps must be ordered.');
        }
    }

    /** @param list<TransactionSplit> $splits */
    public function edit(
        AssetAmount $amount,
        TransactionNature $nature,
        TransactionState $state,
        \DateTimeImmutable $bookedOn,
        ?\DateTimeImmutable $valueOn,
        ?\DateTimeImmutable $authorizedOn,
        string $rawLabel,
        ?string $counterparty,
        ?string $note,
        ?PaymentMethod $paymentMethod,
        ?string $mcc,
        ?string $maskedCard,
        ?string $bankReference,
        array $splits,
        \DateTimeImmutable $updatedAt,
        ?string $lastEditorId,
    ): self {
        if ($this->state->isTerminal()) {
            throw new InvalidTransaction('A terminal transaction cannot be edited.');
        }
        if ($amount->asset->toString() !== $this->amount->asset->toString()) {
            throw new InvalidTransaction('A transaction asset cannot change.');
        }
        if (TransactionSource::MANUAL !== $this->source && $rawLabel !== $this->rawLabel) {
            throw new InvalidTransaction('An external transaction raw label cannot change.');
        }
        if ($state !== $this->state
            && !(TransactionState::PENDING === $this->state && in_array($state, [TransactionState::BOOKED, TransactionState::REJECTED], true))) {
            throw new InvalidTransaction('The requested transaction state transition is not supported.');
        }
        if (null !== $this->reviewReason && $state !== $this->state) {
            // A movement held for review leaves that state through its own
            // resolution ({@see resolveReview}), never through a plain edit:
            // the candidate rows it points at would otherwise be stranded.
            throw new InvalidTransaction('A transaction under reconciliation review must be resolved, not edited into another state.');
        }

        return new self(
            $this->id, $this->workspace, $this->accountId, $amount, $this->originalAmount, $this->exchangeRate,
            $state, $nature, $this->source, $this->sourceRef, $bookedOn, $valueOn, $authorizedOn, $rawLabel,
            $counterparty, $note, $paymentMethod, $mcc, $maskedCard, $bankReference, $splits, $this->version + 1,
            $this->createdAt, $updatedAt, null, $lastEditorId, $this->reviewReason,
        );
    }

    /**
     * Drops the review flag, which is what lets the reconciliation use case
     * then edit the movement into BOOKED. Deliberately the only way out of
     * review: no version is spent here, the edit that follows counts.
     */
    public function resolveReview(): self
    {
        if (null === $this->reviewReason) {
            return $this;
        }

        return new self(
            $this->id, $this->workspace, $this->accountId, $this->amount, $this->originalAmount, $this->exchangeRate,
            $this->state, $this->nature, $this->source, $this->sourceRef, $this->bookedOn, $this->valueOn,
            $this->authorizedOn, $this->rawLabel, $this->counterparty, $this->note, $this->paymentMethod, $this->mcc,
            $this->maskedCard, $this->bankReference, $this->splits, $this->version, $this->createdAt, $this->updatedAt,
            $this->voidedAt, $this->lastEditorId, null,
        );
    }

    /**
     * Takes the stable identifier of the incoming movement this row settles,
     * so the next delivery of the same movement is recognised outright.
     *
     * A row already carrying a different identifier refuses instead: one row
     * holds one identifier, so settling would have to drop one of the two, and
     * the dropped one would stop being recognisable on its own next delivery —
     * a silent path back to counting one movement twice. Such a pair is a real
     * question about two announcements, and it goes to a human.
     */
    public function adoptSourceRef(?string $sourceRef): self
    {
        if (null === $sourceRef || $sourceRef === $this->sourceRef) {
            return $this;
        }
        if (null !== $this->sourceRef) {
            throw new InvalidTransaction('A transaction already carries another external identifier.');
        }

        return new self(
            $this->id, $this->workspace, $this->accountId, $this->amount, $this->originalAmount, $this->exchangeRate,
            $this->state, $this->nature, $this->source, $sourceRef, $this->bookedOn, $this->valueOn,
            $this->authorizedOn, $this->rawLabel, $this->counterparty, $this->note, $this->paymentMethod, $this->mcc,
            $this->maskedCard, $this->bankReference, $this->splits, $this->version, $this->createdAt, $this->updatedAt,
            $this->voidedAt, $this->lastEditorId, $this->reviewReason,
        );
    }

    /**
     * Gives up the stable identifier, for a reviewed movement whose settlement
     * hands it to the row it turned out to be. The identifier is unique per
     * account even across voided rows, so leaving it here would both hide the
     * settled row from the next delivery and block it from taking it.
     */
    public function releaseSourceRef(): self
    {
        if (null === $this->sourceRef) {
            return $this;
        }

        return new self(
            $this->id, $this->workspace, $this->accountId, $this->amount, $this->originalAmount, $this->exchangeRate,
            $this->state, $this->nature, $this->source, null, $this->bookedOn, $this->valueOn,
            $this->authorizedOn, $this->rawLabel, $this->counterparty, $this->note, $this->paymentMethod, $this->mcc,
            $this->maskedCard, $this->bankReference, $this->splits, $this->version, $this->createdAt, $this->updatedAt,
            $this->voidedAt, $this->lastEditorId, $this->reviewReason,
        );
    }

    public function void(\DateTimeImmutable $voidedAt, ?string $lastEditorId): self
    {
        if ($this->state->isTerminal()) {
            throw new InvalidTransaction('A terminal transaction cannot be voided.');
        }

        return new self(
            $this->id, $this->workspace, $this->accountId, $this->amount, $this->originalAmount, $this->exchangeRate,
            TransactionState::VOIDED, $this->nature, $this->source, $this->sourceRef, $this->bookedOn, $this->valueOn,
            $this->authorizedOn, $this->rawLabel, $this->counterparty, $this->note, $this->paymentMethod, $this->mcc,
            $this->maskedCard, $this->bankReference, $this->splits, $this->version + 1, $this->createdAt, $voidedAt,
            $voidedAt, $lastEditorId,
        );
    }

    public function categorize(TransactionSplit $split, ?string $counterparty, \DateTimeImmutable $updatedAt, bool $incrementVersion = true): self
    {
        if ($this->state->isTerminal() || count($this->splits) > 1
            || (isset($this->splits[0]) && CategorizationOrigin::MANUAL === $this->splits[0]->origin)) {
            throw new InvalidTransaction('Automatic categorization only targets an uncategorized live transaction.');
        }

        return new self(
            $this->id, $this->workspace, $this->accountId, $this->amount, $this->originalAmount, $this->exchangeRate,
            $this->state, $this->nature, $this->source, $this->sourceRef, $this->bookedOn, $this->valueOn,
            $this->authorizedOn, $this->rawLabel, $this->counterparty ?? $counterparty, $this->note, $this->paymentMethod,
            $this->mcc, $this->maskedCard, $this->bankReference, [$split], $this->version + ($incrementVersion ? 1 : 0), $this->createdAt,
            $updatedAt, $this->voidedAt, $this->lastEditorId, $this->reviewReason,
        );
    }

    /**
     * The set of splits, if any, must together allocate the transaction exactly:
     * same workspace, asset and sign on every row, no category repeated, at most
     * {@see MAX_SPLITS} rows, and either no split at all or a sum matching the
     * transaction amount to the last digit. There is no partial allocation.
     *
     * @param list<TransactionSplit> $splits
     */
    private static function assertSplits(string $id, WorkspaceScope $workspace, AssetAmount $amount, array $splits): void
    {
        if (count($splits) > self::MAX_SPLITS) {
            throw new InvalidTransaction(sprintf('A transaction carries at most %d splits.', self::MAX_SPLITS));
        }

        $seenCategories = [];
        foreach ($splits as $split) {
            if ($split->workspace->id !== $workspace->id || $split->transactionId !== $id
                || $split->amount->asset->toString() !== $amount->asset->toString()
                || $split->amount->value->isNegative() !== $amount->value->isNegative()) {
                throw new InvalidTransaction('A transaction split must share the workspace, asset and sign of its transaction.');
            }
            if (isset($seenCategories[$split->categoryId])) {
                throw new InvalidTransaction('A transaction split cannot repeat a category.');
            }
            $seenCategories[$split->categoryId] = true;
        }

        if ([] === $splits) {
            return;
        }

        $sum = ExactDecimal::sum(...array_map(static fn (TransactionSplit $split): DecimalValue => $split->amount->value, $splits));
        if (0 !== $sum->compareTo($amount->value)) {
            throw new InvalidTransaction('A transaction split allocation must sum exactly to its amount.');
        }
    }

    private static function assertSign(AssetAmount $amount, TransactionNature $nature): void
    {
        $negative = $amount->value->isNegative();
        if ((in_array($nature, [TransactionNature::INCOME, TransactionNature::REFUND], true) && $negative)
            || (in_array($nature, [TransactionNature::EXPENSE, TransactionNature::FEE], true) && !$negative)) {
            throw new InvalidTransaction('The transaction sign contradicts its nature.');
        }
    }

    private static function identifier(string $id, string $field): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidTransaction(sprintf('A %s identifier must be a canonical UUID.', $field));
        }
    }

    private static function requiredText(string $value, int $maximum, string $field): void
    {
        if ($value !== trim($value) || '' === $value || mb_strlen($value) > $maximum) {
            throw new InvalidTransaction(sprintf('A %s must contain between 1 and %d characters.', $field, $maximum));
        }
    }

    private static function optionalText(?string $value, int $maximum, string $field): void
    {
        if (null !== $value) {
            self::requiredText($value, $maximum, $field);
        }
    }

    private static function digits(?string $value, string $field): void
    {
        if (null !== $value && 1 !== preg_match('/^[0-9]{4}$/D', $value)) {
            throw new InvalidTransaction(sprintf('A %s must carry exactly four digits.', $field));
        }
    }
}
