<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;

final readonly class AccountView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $assetCode,
        public string $kind,
        public ?string $productCode,
        public ?string $productModelId,
        public ?string $institution,
        public ?string $maskedIdentifier,
        public string $valuationMode,
        public string $liquidityLevel,
        public bool $includeInNetWorth,
        public bool $includeInEmergencyFund,
        public string $openedOn,
        public ?string $closedOn,
        public string $status,
        public int $netWorthSign,
        public bool $used,
        public bool $editable,
        public bool $kindEditable,
        public ?string $kindEditReason,
        public int $version,
        public ?string $archivedAt,
        public ?string $primaryGroupId = null,
        /** @var list<string> */
        public array $tagGroupIds = [],
        public ?ShareView $share = null,
        public ?ValuationView $valuation = null,
    ) {
    }

    public static function fromAccount(Account $account, ?ShareView $share = null, ?ValuationView $valuation = null): self
    {
        $archived = null !== $account->archivedAt;

        return new self(
            id: $account->id,
            label: $account->label,
            assetCode: $account->assetCode->toString(),
            kind: $account->kind->value,
            // The catalogue or model reference only, never both. The rules
            // behind either are read on the business date they are needed,
            // never copied into the account.
            productCode: $account->productCode?->toString(),
            productModelId: $account->productModelId,
            institution: $account->institution,
            maskedIdentifier: $account->maskedIdentifier?->toString(),
            valuationMode: $account->valuationMode->value,
            liquidityLevel: $account->liquidityLevel->value,
            includeInNetWorth: $account->includeInNetWorth,
            includeInEmergencyFund: $account->includeInEmergencyFund,
            openedOn: $account->openedOn->format('Y-m-d'),
            closedOn: $account->closedOn?->format('Y-m-d'),
            status: match (true) {
                $archived => 'ARCHIVED',
                $account->isClosed() => 'CLOSED',
                default => 'ACTIVE',
            },
            // The convention the interface displays instead of deducing it from
            // a kind list of its own: an asset counts positively, a liability
            // negatively, and no balance is implied either way.
            netWorthSign: $account->netWorthSign(),
            used: null !== $account->usedAt,
            editable: !$archived,
            kindEditable: !$archived && null === $account->usedAt,
            kindEditReason: match (true) {
                $archived => 'ARCHIVED',
                null !== $account->usedAt => 'USED',
                default => null,
            },
            version: $account->version,
            archivedAt: $account->archivedAt?->format(DATE_ATOM),
            primaryGroupId: $account->primaryGroupId,
            tagGroupIds: $account->tagGroupIds,
            share: $share ?? ($account->includeInNetWorth ? ShareView::missingValuation() : ShareView::notApplicable()),
            valuation: $valuation,
        );
    }
}
