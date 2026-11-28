<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\DeclaredRuleValue;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;

/**
 * Translates between an account rule override and the two row sets that store
 * it, in both directions.
 *
 * The two mappings sit together on purpose: a column added to the write side
 * and forgotten on the read side is the failure this class exists to make
 * obvious. DBAL yields `mixed` for every column, so the narrowing helpers
 * assert the shape the domain expects rather than casting a surprise away.
 */
final readonly class AccountRuleOverrideRow
{
    /**
     * The workspace is compared, never taken from the row: a query that leaked
     * another workspace's record must fail loudly here instead of hydrating an
     * object that looks legitimate.
     *
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $brackets
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $brackets): AccountRuleOverride
    {
        if ($workspace->id !== DbalValue::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('An account rule override row escaped its requested workspace.');
        }

        $kind = RuleKind::from(DbalValue::text($row['rule_kind'] ?? null));
        $withdrawnAt = $row['withdrawn_at'] ?? null;

        return new AccountRuleOverride(
            id: DbalValue::text($row['id'] ?? null),
            workspace: $workspace,
            accountId: DbalValue::text($row['account_id'] ?? null),
            kind: $kind,
            value: match ($kind->valueType()) {
                RuleValueType::AMOUNT => DeclaredRuleValue::amount(new AssetAmount(
                    DecimalValue::fromString(DbalValue::text($row['amount_value'] ?? null)),
                    AssetCode::fromString(DbalValue::text($row['amount_asset'] ?? null)),
                )),
                RuleValueType::PERCENTAGE => DeclaredRuleValue::rate(new RateScale(
                    array_map(self::bracket(...), $brackets),
                    RateApplication::from(DbalValue::text($row['rate_application'] ?? null)),
                )),
                RuleValueType::TEXT => DeclaredRuleValue::text(DbalValue::text($row['text_value'] ?? null)),
            },
            period: new EffectivePeriod(
                validFrom: DbalValue::businessDay($row['valid_from'] ?? null),
                validTo: null === ($row['valid_to'] ?? null) ? null : DbalValue::businessDay($row['valid_to']),
            ),
            reason: DbalValue::text($row['reason'] ?? null),
            authorId: DbalValue::text($row['author_id'] ?? null),
            recordedAt: new \DateTimeImmutable(DbalValue::text($row['recorded_at'] ?? null)),
            withdrawnAt: null === $withdrawnAt ? null : new \DateTimeImmutable(DbalValue::text($withdrawnAt)),
            withdrawnBy: null === ($row['withdrawn_by'] ?? null) ? null : DbalValue::text($row['withdrawn_by']),
        );
    }

    /**
     * The columns written once, when the override is recorded.
     *
     * The withdrawal pair is absent: an override is recorded standing, and
     * withdrawing it is its own statement rather than a field of this one.
     * `workspace_id` is absent too, and named by the repository at the insert
     * itself, so the scope of a write stays visible where the write is.
     *
     * @return array<string, mixed>
     */
    public static function columns(AccountRuleOverride $override): array
    {
        $amount = $override->value->amount;
        $scale = $override->value->scale;

        return [
            'id' => $override->id,
            'account_id' => $override->accountId,
            'rule_kind' => $override->kind->value,
            'amount_value' => $amount?->value->toString(),
            'amount_asset' => $amount?->asset->toString(),
            'text_value' => $override->value->text,
            'rate_application' => $scale?->application->value,
            'valid_from' => $override->period->validFrom->format('Y-m-d'),
            'valid_to' => $override->period->validTo?->format('Y-m-d'),
            'reason' => $override->reason,
            'author_id' => $override->authorId,
            'recorded_at' => $override->recordedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function bracket(array $row): RateBracket
    {
        $upperBound = $row['upper_bound'] ?? null;

        return new RateBracket(
            DecimalValue::fromString(DbalValue::text($row['lower_bound'] ?? null)),
            null === $upperBound ? null : DecimalValue::fromString(DbalValue::text($upperBound)),
            DecimalValue::fromString(DbalValue::text($row['percentage'] ?? null)),
        );
    }
}
