<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\OperationCashEffect;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;
use App\Module\Transactions\Application\ReadTransferLeg;

/**
 * Turns what the client typed into validated operation details and the server
 * derived cash effect. It reads the account, the instruments and the linked leg;
 * it writes nothing, so a preview and a create share exactly the same rules.
 */
final readonly class OperationResolver
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(
        private AssetCatalog $assets,
        private InstrumentRepository $instruments,
        private PortfolioOperationRepository $operations,
        private ReadTransferLeg $transferLegs,
    ) {
    }

    public static function parseTradeDate(string $literal): \DateTimeImmutable
    {
        return self::date($literal, 'invalid_operation_date');
    }

    /**
     * @param bool        $lock                 lock the instruments for update, for a write
     * @param string|null $replacingOperationId the operation a correction replaces, whose own transfer link stays available
     */
    public function resolve(Account $account, OperationInput $input, \DateTimeImmutable $today, bool $lock, ?string $replacingOperationId = null): ResolvedOperation
    {
        $asset = $this->assets->findByCode($account->assetCode) ?? throw new \LogicException('The account asset is missing from the reference.');
        $type = OperationType::tryFrom($input->type) ?? throw new InvalidPortfolioOperation('The operation type is not supported.', 'OTHER_CORPORATE_ACTION' === $input->type ? 'unsupported_operation_type' : 'invalid_operation_type');
        $tradeDate = self::parseTradeDate($input->tradeDate);
        $settlementDate = null === $input->settlementDate ? null : self::date($input->settlementDate, 'invalid_settlement_date');

        $details = new OperationDetails(
            type: $type,
            tradeDate: $tradeDate,
            settlementDate: $settlementDate,
            instrumentId: $input->instrumentId,
            counterInstrumentId: $input->counterInstrumentId,
            quantity: self::figure($input->quantity, 'invalid_operation_quantity', 'quantity_precision_exceeded'),
            unitPrice: self::figure($input->unitPrice, 'invalid_operation_amount', 'price_precision_exceeded'),
            grossAmount: self::amount($input->grossAmount, $asset),
            fees: self::amount($input->fees, $asset),
            taxes: self::amount($input->taxes, $asset),
            costBasis: self::amount($input->costBasis, $asset),
            counterQuantity: self::figure($input->counterQuantity, 'invalid_operation_quantity', 'quantity_precision_exceeded'),
            ratioNumerator: self::figure($input->ratioNumerator, 'invalid_corporate_action', 'invalid_corporate_action'),
            ratioDenominator: self::figure($input->ratioDenominator, 'invalid_corporate_action', 'invalid_corporate_action'),
            costAllocationRatio: self::figure($input->costAllocationRatio, 'invalid_corporate_action', 'invalid_corporate_action'),
            reference: $input->reference,
            note: $input->note,
            linkedTransactionId: $input->linkedTransactionId,
        );

        $this->assertDates($account, $tradeDate, $today);
        $resolvedInstruments = $this->resolveInstruments($account, $details, $lock);
        $this->assertLinkedTransfer($account, $details, $replacingOperationId);

        $effect = OperationCashEffect::derive(
            $type, $details->quantity, $details->unitPrice, $details->grossAmount,
            $details->fees ?? DecimalValue::zero(), $details->taxes ?? DecimalValue::zero(), $asset->precision->display,
        );

        return new ResolvedOperation($details, $effect, $resolvedInstruments);
    }

    private function assertDates(Account $account, \DateTimeImmutable $tradeDate, \DateTimeImmutable $today): void
    {
        $day = $tradeDate->format('Y-m-d');
        if ($day < $account->openedOn->format('Y-m-d')
            || $day > $today->format('Y-m-d')
            || (null !== $account->closedOn && $day > $account->closedOn->format('Y-m-d'))) {
            throw new InvalidPortfolioOperation('The trade date lies outside the account lifetime or in the future.', 'invalid_operation_date');
        }
    }

    /** @return list<Instrument> */
    private function resolveInstruments(Account $account, OperationDetails $details, bool $lock): array
    {
        $ids = array_values(array_unique(array_filter([$details->instrumentId, $details->counterInstrumentId])));
        // A fixed order keeps two writers naming the same instruments in opposite order from deadlocking.
        sort($ids);
        $resolved = [];
        foreach ($ids as $id) {
            $instrument = 1 === preg_match(self::UUID_PATTERN, $id)
                ? ($lock ? $this->instruments->findForUpdate($account->workspace, $id) : $this->instruments->find($account->workspace, $id))
                : null;
            if (null === $instrument) {
                throw new InvalidPortfolioOperation('The instrument does not exist.', 'unknown_instrument');
            }
            if (InstrumentStatus::ACTIVE !== $instrument->status) {
                throw new InvalidPortfolioOperation('The instrument is inactive.', 'instrument_inactive');
            }
            if (!$instrument->currencyCode->equals($account->assetCode)) {
                throw new InvalidPortfolioOperation('The instrument currency differs from the account currency.', 'instrument_currency_mismatch');
            }
            $resolved[$id] = $instrument;
        }

        $main = null === $details->instrumentId ? null : $resolved[$details->instrumentId];
        $counter = null === $details->counterInstrumentId ? null : $resolved[$details->counterInstrumentId];
        if (null !== $main) {
            self::assertScale($details->quantity, $main->quantityPrecision, 'quantity_precision_exceeded');
            self::assertScale($details->unitPrice, $main->pricePrecision, 'price_precision_exceeded');
        }
        if (null !== $counter) {
            self::assertScale($details->counterQuantity, $counter->quantityPrecision, 'quantity_precision_exceeded');
        }

        return array_values($resolved);
    }

    private function assertLinkedTransfer(Account $account, OperationDetails $details, ?string $replacingOperationId): void
    {
        $linkedId = $details->linkedTransactionId;
        if (null === $linkedId) {
            return;
        }
        $leg = 1 === preg_match(self::UUID_PATTERN, $linkedId) ? ($this->transferLegs)($account->workspace, $linkedId) : null;
        $gross = $details->grossAmount;
        $mismatch = static fn (): InvalidPortfolioOperation => new InvalidPortfolioOperation('The linked transfer leg does not mirror this operation.', 'linked_transfer_mismatch');
        if (null === $leg || null === $gross
            || $leg->accountId !== $account->id
            || 'BOOKED' !== $leg->state || $leg->voided
            || !$leg->amount->asset->equals($account->assetCode)
            || $leg->amount->value->isNegative() !== (OperationType::WITHDRAWAL === $details->type)
            || 0 !== ExactDecimal::absolute($leg->amount->value)->compareTo($gross)
            || $leg->bookedOn->format('Y-m-d') !== $details->tradeDate->format('Y-m-d')) {
            throw $mismatch();
        }
        $holder = $this->operations->activeOperationLinkedTo($account->workspace, $linkedId);
        if (null !== $holder && $holder !== $replacingOperationId) {
            throw new LinkedTransferTaken('The transfer leg already backs an active operation.');
        }
    }

    private static function date(string $literal, string $code): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidPortfolioOperation('A date is a real ISO 8601 calendar day.', $code);
        }

        return $date;
    }

    private static function amount(?AmountInput $input, Asset $asset): ?DecimalValue
    {
        if (null === $input) {
            return null;
        }
        if ($input->assetCode !== $asset->code->toString()) {
            throw new InvalidPortfolioOperation('Every amount is in the account currency.', 'operation_asset_mismatch');
        }
        try {
            return $asset->amount($input->value)->value;
        } catch (MalformedDecimal|PrecisionExceeded) {
            throw new InvalidPortfolioOperation('An amount is a canonical decimal within the currency precision.', 'invalid_operation_amount');
        }
    }

    private static function figure(?string $literal, string $malformedCode, string $precisionCode): ?DecimalValue
    {
        if (null === $literal) {
            return null;
        }
        try {
            return DecimalValue::fromString($literal);
        } catch (MalformedDecimal) {
            throw new InvalidPortfolioOperation('A figure is a canonical decimal string.', $malformedCode);
        } catch (PrecisionExceeded) {
            throw new InvalidPortfolioOperation('A figure exceeds the storable precision.', $precisionCode);
        }
    }

    private static function assertScale(?DecimalValue $value, int $precision, string $code): void
    {
        if (null !== $value && $value->scale() > $precision) {
            throw new InvalidPortfolioOperation('The figure carries more decimals than the instrument allows.', $code);
        }
    }
}
