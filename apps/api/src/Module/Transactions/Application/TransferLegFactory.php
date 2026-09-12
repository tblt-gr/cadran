<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Transactions\Domain\InvalidTransfer;
use App\Module\Transactions\Domain\Transfer;

/**
 * Turns the positive magnitudes a transfer request submits into the two
 * signed leg amounts the domain works with, applying the signs itself so a
 * client can never invert a leg.
 */
final readonly class TransferLegFactory
{
    public function __construct(private AmountInputParser $amountParser)
    {
    }

    public function plan(mixed $sourceAmountInput, mixed $targetAmountInput, Account $sourceAccount, Account $targetAccount): TransferLegPlan
    {
        $sourceMagnitude = ($this->amountParser)($sourceAmountInput, '/sourceAmount');
        self::assertPositiveMagnitude($sourceMagnitude);
        if ($sourceMagnitude->asset->toString() !== $sourceAccount->assetCode->toString()) {
            throw new InvalidTransferInput('The source amount must be denominated in the source account asset.');
        }

        $crossAsset = $sourceAccount->assetCode->toString() !== $targetAccount->assetCode->toString();
        $targetMagnitude = null === $targetAmountInput ? null : ($this->amountParser)($targetAmountInput, '/targetAmount');
        if (null !== $targetMagnitude) {
            self::assertPositiveMagnitude($targetMagnitude);
            if ($targetMagnitude->asset->toString() !== $targetAccount->assetCode->toString()) {
                throw new InvalidTransferInput('The target amount must be denominated in the target account asset.');
            }
        } elseif ($crossAsset) {
            throw new InvalidTransferInput('A cross-asset transfer requires an explicit target amount.');
        } else {
            $targetMagnitude = new AssetAmount($sourceMagnitude->value, $targetAccount->assetCode);
        }

        $sourceLeg = new AssetAmount(ExactDecimal::negate($sourceMagnitude->value), $sourceAccount->assetCode);
        $targetLeg = new AssetAmount($targetMagnitude->value, $targetAccount->assetCode);

        try {
            Transfer::assertOpposition($sourceLeg, $targetLeg);
        } catch (InvalidTransfer $exception) {
            throw new InvalidTransferRule(InvalidTransferRule::AMOUNT_MISMATCH, $exception);
        }

        return new TransferLegPlan(
            sourceAmount: $sourceLeg,
            targetAmount: $targetLeg,
            sourceOriginal: $crossAsset ? $targetMagnitude : null,
            targetOriginal: $crossAsset ? $sourceMagnitude : null,
            exchangeRate: $crossAsset ? Transfer::deriveExchangeRate($sourceLeg, $targetLeg) : null,
            crossAsset: $crossAsset,
        );
    }

    public function fee(mixed $feeInput, Account $sourceAccount): ?AssetAmount
    {
        if (null === $feeInput) {
            return null;
        }

        $fee = ($this->amountParser)($feeInput, '/fee');
        if (!$fee->value->isNegative()) {
            throw new InvalidTransferInput('A transfer fee must be a negative amount.');
        }
        if ($fee->asset->toString() !== $sourceAccount->assetCode->toString()) {
            throw new InvalidTransferInput('A transfer fee must be denominated in the source account asset.');
        }

        return $fee;
    }

    private static function assertPositiveMagnitude(AssetAmount $amount): void
    {
        if ($amount->value->isNegative() || 0 === $amount->value->compareTo(DecimalValue::zero())) {
            throw new InvalidTransferInput('A transfer amount must be a strictly positive magnitude.');
        }
    }
}
