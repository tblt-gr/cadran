<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionState;

final readonly class ReadRefundable
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private TransactionRepository $transactions,
        private RefundRepository $refunds,
        private AssetCatalog $assets,
        private RefundAllocation $allocation,
    ) {
    }

    public function __invoke(string $id): RefundableView
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $original = $this->transactions->find($workspace, $id);
        if (null === $original) {
            throw new TransactionNotFound();
        }
        $this->assertOriginal($original);
        $refunded = $this->refunds->refundedAmount($workspace, $id);
        $refundable = ExactDecimal::subtract(ExactDecimal::absolute($original->amount->value), $refunded);
        $asset = $this->assets->findByCode($original->amount->asset) ?? throw new \UnexpectedValueException('Transaction asset is missing.');
        $proposal = $this->allocation->propose($original, new AssetAmount($refundable, $original->amount->asset), $asset->precision->display);

        return new RefundableView(
            $id,
            ['value' => $original->amount->value->toString(), 'assetCode' => $original->amount->asset->toString()],
            ['value' => $refunded->toString(), 'assetCode' => $original->amount->asset->toString()],
            ['value' => $refundable->toString(), 'assetCode' => $original->amount->asset->toString()],
            array_map(static fn (array $row): array => ['categoryId' => $row['categoryId'], 'amount' => ['value' => $row['amount']->value->toString(), 'assetCode' => $row['amount']->asset->toString()]], $proposal),
        );
    }

    private function assertOriginal(Transaction $original): void
    {
        if (TransactionState::BOOKED !== $original->state) {
            throw new InvalidRefundRule('original_not_refundable');
        }
        if (!in_array($original->nature, [TransactionNature::EXPENSE, TransactionNature::FEE], true)) {
            throw new InvalidRefundRule('original_nature');
        }
    }
}
