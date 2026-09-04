<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CeilingBasis;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Catalog\Domain\ProductNature;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A product model owned by one workspace: what a bank-specific savings
 * product, a private loan or a hand-described contract is, independently of
 * any account created from it.
 *
 * It exists beside the system catalogue rather than inside it. The catalogue
 * is global, sourced and read-only, and a workspace must be able to model the
 * product its own institution actually sells — a promotional rate, a tiered
 * scale, a ceiling no publication states. Keeping the two apart is what stops
 * a workspace edit from reaching a system product, and a catalogue revision
 * from overwriting a figure the holder entered.
 *
 * Like the catalogue, the model stores dated periods and never a single
 * current figure: a rate is asked for on a business date, and last year's
 * statement resolves against last year's rate.
 */
final readonly class ProductModel
{
    public const int MAX_NAME_LENGTH = 80;
    public const int MAX_GROUP_CODE_LENGTH = 32;
    /**
     * A model is a description, not a history table. The bound keeps one
     * workspace from turning a single row set into an unbounded read for every
     * later resolution.
     */
    public const int MAX_RULE_PERIODS = 200;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $name,
        public AccountKind $family,
        public WrapperKind $wrapperKind,
        public YieldKind $yieldKind,
        public ?string $defaultGroupCode,
        public AccountValuationMode $valuationMode,
        public ProductCapabilities $capabilities,
        public ModelProvenance $provenance,
        public ModelRuleSchedule $schedule,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $archivedAt = null,
    ) {
        self::assertIdentifier($id);
        self::assertName($name);
        self::assertGroupCode($defaultGroupCode);

        $supportsLiability = $capabilities->contains(ProductCapability::SUPPORTS_LIABILITY);
        if (AccountKind::LIABILITY === $family && !$supportsLiability) {
            throw new InvalidProductModel('A LIABILITY model requires SUPPORTS_LIABILITY.');
        }

        if (AccountKind::LIABILITY !== $family && $supportsLiability) {
            throw new InvalidProductModel('SUPPORTS_LIABILITY is exclusive to LIABILITY models.');
        }

        if (!$valuationMode->acceptsKind($family)) {
            throw new InvalidProductModel('A portfolio valuation requires a family that holds positions.');
        }

        if (!$capabilities->contains($valuationMode->requiredCapability())) {
            throw new InvalidProductModel('The valuation mode requires a capability this model does not declare.');
        }

        // The promise-of-return invariant, held here exactly as the catalogue
        // holds it: a model valued by the market cannot carry a rate, so no
        // screen and no later estimation can turn an assumption into a
        // guaranteed yield.
        if (!$yieldKind->acceptsRateRule() && $schedule->hasRateRule()) {
            throw new InvalidProductModel(sprintf('A %s model carries no rate period.', $yieldKind->value));
        }

        foreach ($schedule->rules as $rule) {
            $required = $rule->kind->requiredCapability();
            if (null !== $required && !$capabilities->contains($required)) {
                throw new InvalidProductModel(sprintf('%s periods require %s.', $rule->kind->value, $required->value));
            }
        }

        if (count($schedule->rules) > self::MAX_RULE_PERIODS) {
            throw new InvalidProductModel(sprintf('A model records at most %d rule periods.', self::MAX_RULE_PERIODS));
        }

        if ($version < 1) {
            throw new InvalidProductModel('A model version must be positive.');
        }

        if ($updatedAt < $createdAt) {
            throw new InvalidProductModel('A model cannot be updated before it was created.');
        }
    }

    /**
     * Which side of the balance sheet the model sits on, derived from its
     * family so the two can never disagree.
     */
    public function nature(): ProductNature
    {
        return $this->family->nature();
    }

    /**
     * What a ceiling on this model is measured against.
     *
     * A recorded ceiling kind is the source of truth: a NONE envelope that
     * still carries a balance ceiling is measured on the total balance, not
     * reported as having no ceiling. Several kinds that disagree cannot be
     * collapsed into one measure, so the view says NONE rather than picking.
     * When no ceiling period is recorded yet, the envelope's expected kind
     * remains the fallback — a regulated passbook is still capped on deposits
     * even before the holder types an amount.
     */
    public function ceilingBasis(): CeilingBasis
    {
        $bases = [];
        foreach ($this->schedule->rules as $rule) {
            $basis = $rule->kind->ceilingBasis();
            if (CeilingBasis::NONE !== $basis) {
                $bases[$basis->value] = $basis;
            }
        }

        $unique = array_values($bases);
        if (1 === count($unique)) {
            return $unique[0];
        }

        if ([] === $unique) {
            return $this->wrapperKind->ceilingBasis();
        }

        return CeilingBasis::NONE;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    /**
     * Resolves the periods applying on $businessDate, the counterpart of
     * {@see \App\Module\Catalog\Domain\CatalogEntry::effectiveOn()} for a
     * workspace model.
     *
     * Archiving is answered here exactly like an active model: it stops new
     * use, never what an existing account resolves against, so an account
     * created from an archived model keeps reading the same periods it always
     * did.
     */
    public function effectiveOn(\DateTimeImmutable $businessDate): EffectiveModel
    {
        $effective = $this->schedule->effectiveOn($businessDate);

        $kindsFound = array_map(static fn (ModelRule $rule): RuleKind => $rule->kind, $effective);
        $expectedKinds = [
            ...$this->wrapperKind->expectedRuleKinds(),
            ...$this->yieldKind->expectedRuleKinds(),
        ];
        $unavailable = [];
        foreach ($expectedKinds as $expected) {
            if (!in_array($expected, $kindsFound, true)) {
                $unavailable[] = $expected;
            }
        }

        return new EffectiveModel(
            model: $this,
            asOf: $businessDate,
            rules: $effective,
            unavailableRuleKinds: $unavailable,
        );
    }

    /**
     * Records a dated period. Editing a model is adding to it: an open-ended
     * period the new one supersedes is closed the day before it starts, and
     * nothing already recorded is rewritten or removed.
     */
    public function withRule(ModelRule $rule, \DateTimeImmutable $updatedAt): self
    {
        $this->assertWritable();

        return $this->with($this->schedule->appended($rule), $updatedAt, $this->archivedAt);
    }

    /**
     * A copy of this model under a new identity and name.
     *
     * The copy takes the description and the dated periods with their
     * effective dates untouched, and nothing else: no account, no balance, no
     * valuation, no external identifier ever belonged to the model, so a
     * duplicate cannot carry one. Its provenance records the model it started
     * from, and it stops following it from that moment.
     *
     * @param list<string> $ruleIds one fresh identifier per copied period
     */
    public function duplicateAs(string $id, string $name, array $ruleIds, \DateTimeImmutable $now): self
    {
        return new self(
            id: $id,
            workspace: $this->workspace,
            name: $name,
            family: $this->family,
            wrapperKind: $this->wrapperKind,
            yieldKind: $this->yieldKind,
            defaultGroupCode: $this->defaultGroupCode,
            valuationMode: $this->valuationMode,
            capabilities: $this->capabilities,
            provenance: ModelProvenance::fromWorkspaceModel($this->id),
            schedule: $this->schedule->copyWithIds($ruleIds),
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Archiving is how a model leaves the working set. Deletion is not
     * offered: an account created from a model must keep resolving against the
     * description it was created with, so the model stays readable by
     * identifier for as long as anything references it.
     */
    public function archive(\DateTimeImmutable $archivedAt): self
    {
        $this->assertWritable();

        return $this->with($this->schedule, $archivedAt, $archivedAt);
    }

    private function with(ModelRuleSchedule $schedule, \DateTimeImmutable $updatedAt, ?\DateTimeImmutable $archivedAt): self
    {
        return new self(
            id: $this->id,
            workspace: $this->workspace,
            name: $this->name,
            family: $this->family,
            wrapperKind: $this->wrapperKind,
            yieldKind: $this->yieldKind,
            defaultGroupCode: $this->defaultGroupCode,
            valuationMode: $this->valuationMode,
            capabilities: $this->capabilities,
            provenance: $this->provenance,
            schedule: $schedule,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
            archivedAt: $archivedAt,
        );
    }

    private function assertWritable(): void
    {
        if ($this->isArchived()) {
            throw new ProductModelIsArchived('An archived model is read-only.');
        }
    }

    private static function assertIdentifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidProductModel('A model identifier must be a canonical UUID.');
        }
    }

    private static function assertName(string $name): void
    {
        if ($name !== trim($name) || '' === $name || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidProductModel(sprintf('A model name must contain between 1 and %d characters.', self::MAX_NAME_LENGTH));
        }

        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $name)) {
            throw new InvalidProductModel('A model name cannot contain control characters.');
        }
    }

    private static function assertGroupCode(?string $groupCode): void
    {
        if (null === $groupCode) {
            return;
        }

        if (strlen($groupCode) > self::MAX_GROUP_CODE_LENGTH || 1 !== preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D', $groupCode)) {
            throw new InvalidProductModel(sprintf('A group code is an uppercase token of at most %d characters.', self::MAX_GROUP_CODE_LENGTH));
        }
    }
}
