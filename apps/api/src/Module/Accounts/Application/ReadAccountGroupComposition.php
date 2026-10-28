<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\AccountGroupColors;
use App\Module\Accounts\Domain\AccountGroupRepository;
use App\Module\Accounts\Domain\AccountGroupTree;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\CompositionSlice;
use App\Module\Accounts\Domain\GroupCompositionCalculator;
use App\Module\Accounts\Domain\RankedAccount;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Reference\Application\AssetCatalog;

/**
 * How one group splits between its sub-groups and its accounts, weighed
 * against the group's own roll-up on one day.
 */
final readonly class ReadAccountGroupComposition
{
    public const int MAX_ACCOUNTS = 1000;

    public function __construct(
        private CallerWorkspace $caller,
        private AccountOverviewFigures $figures,
        private AccountGroupRepository $groups,
        private AccountRepository $accounts,
        private AssetCatalog $assets,
    ) {
    }

    /**
     * @throws AccountGroupNotFound        when the group is unknown, foreign or archived
     * @throws GroupCompositionTooLarge    beyond {@see MAX_ACCOUNTS} accounts in the subtree
     * @throws InvalidAccountOverviewQuery on a malformed date
     */
    public function __invoke(string $groupId, ?string $asOf): GroupCompositionView
    {
        $workspace = $this->caller->resolve();
        $group = $this->groups->find($workspace, $groupId);
        if (null === $group || null !== $group->archivedAt) {
            throw new AccountGroupNotFound();
        }

        $figures = $this->figures->on($workspace, $asOf);
        $subtreeIds = [];
        foreach ($figures->lineages as $lineage) {
            if (in_array($groupId, $lineage->ancestorIdsIncludingSelf, true)) {
                $subtreeIds[$lineage->groupId] = true;
            }
        }
        $ordered = array_values(array_filter(
            AccountGroupTree::depthFirst($figures->groups),
            static fn (AccountGroup $candidate): bool => isset($subtreeIds[$candidate->id]),
        ));
        $children = array_values(array_filter($ordered, static fn (AccountGroup $candidate): bool => $candidate->parentId === $groupId));

        $ranked = $this->accounts->listInGroupsOrder(
            $workspace,
            array_map(static fn (AccountGroup $member): string => $member->id, $ordered),
            true,
            true,
            0,
            self::MAX_ACCOUNTS + 1,
        );
        if (count($ranked) > self::MAX_ACCOUNTS) {
            throw new GroupCompositionTooLarge('This group holds more accounts than one composition reads.');
        }

        $eligible = [];
        foreach ($figures->contributions as $contribution) {
            if ($contribution->eligible && null !== $contribution->primaryGroupId && isset($subtreeIds[$contribution->primaryGroupId])) {
                $eligible[$contribution->accountId] = $contribution;
            }
        }
        $members = array_values(array_filter($ranked, static fn (RankedAccount $row): bool => isset($eligible[$row->account->id])));
        $inputs = array_map(
            static fn (RankedAccount $row): AccountShareInput => $eligible[$row->account->id]->share(),
            $members,
        );

        $composition = GroupCompositionCalculator::compute(
            $groupId,
            $inputs,
            $figures->lineages,
            array_map(static fn (AccountGroup $child): string => $child->id, $children),
        );

        $colors = AccountGroupColors::of($figures->groups);
        $groupsById = [];
        foreach ($figures->groups as $candidate) {
            $groupsById[$candidate->id] = $candidate;
        }
        $asset = $composition->asset ?? $figures->netWorth->asset;

        $bySubgroup = [];
        foreach ($children as $child) {
            $slice = $composition->subgroups[$child->id] ?? null;
            if (null !== $slice) {
                $bySubgroup[] = $this->entry('SUBGROUP', $child->id, $child->label, $colors->effective($child), $slice, $asset);
            }
        }
        if (null !== $composition->direct && [] !== $bySubgroup) {
            $bySubgroup[] = $this->entry('DIRECT', $group->id, $group->label, $colors->effective($group), $composition->direct, $asset);
        }

        $byAccount = [];
        foreach ($members as $row) {
            $account = $row->account;
            $primary = null === $account->primaryGroupId ? null : $groupsById[$account->primaryGroupId] ?? null;
            $byAccount[] = $this->entry(
                null,
                $account->id,
                $account->label,
                $colors->effective($primary ?? $group),
                $composition->accounts[$account->id],
                $eligible[$account->id]->share()->asset ?? $asset,
                $account->institution,
                $account->primaryGroupId,
            );
        }

        return new GroupCompositionView(
            groupId: $group->id,
            label: $group->label,
            asOf: $figures->day->format('Y-m-d'),
            total: $this->amount($composition->total, $asset),
            totalReason: $composition->totalReason?->value,
            chartReason: $composition->chartReason?->value,
            bySubgroup: $bySubgroup,
            byAccount: $byAccount,
        );
    }

    private function entry(
        ?string $kind,
        string $id,
        string $label,
        string $color,
        CompositionSlice $slice,
        ?AssetCode $asset,
        ?string $institution = null,
        ?string $primaryGroupId = null,
    ): GroupCompositionEntryView {
        return new GroupCompositionEntryView(
            $kind,
            $id,
            $label,
            $color,
            $this->amount($slice->value, $asset),
            ShareView::fromShare($slice->share),
            $institution,
            $primaryGroupId,
        );
    }

    private function amount(?DecimalValue $value, ?AssetCode $asset): ?NetWorthAmountView
    {
        return NetWorthAmountView::of($value, $asset, null === $asset ? null : $this->assets->findByCode($asset));
    }
}
