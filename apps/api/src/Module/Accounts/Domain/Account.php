<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A financial account of one workspace: what it is, what it is denominated in,
 * how it will be valued and whether it counts towards net worth.
 *
 * It carries no balance. A value arrives later from recorded movements, dated
 * valuations or portfolio positions, and the absence of one stays absent: this
 * aggregate never invents a starting figure to make an account look complete.
 *
 * A product-backed account keeps only the catalogue reference, never a copy of
 * what the catalogue said. A ceiling or a rate is read from the catalogue on
 * the business date it is needed, so a regulatory revision reaches every
 * account at once instead of freezing yesterday's figure into a row.
 *
 * A model-backed account works the same way, one reference away: it keeps
 * only the identifier of the workspace product model it was created from, and
 * the ceilings, rates and terms behind it are read from that model on the
 * business date they are needed. An account carries at most one of the two
 * references — never both at once, since each names a different authority for
 * the rules the account inherits.
 */
final readonly class Account
{
    public const int MAX_LABEL_LENGTH = 80;
    public const int MAX_INSTITUTION_LENGTH = 80;
    public const string MIN_OPENED_ON = '1900-01-01';

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $label,
        public AssetCode $assetCode,
        public AccountKind $kind,
        public ?ProductCode $productCode,
        public ?string $productModelId,
        public ?string $institution,
        public ?MaskedIdentifier $maskedIdentifier,
        public AccountValuationMode $valuationMode,
        public LiquidityLevel $liquidityLevel,
        public bool $includeInNetWorth,
        public bool $includeInEmergencyFund,
        public \DateTimeImmutable $openedOn,
        public ?\DateTimeImmutable $closedOn,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $usedAt = null,
        public ?\DateTimeImmutable $archivedAt = null,
        public ?string $primaryGroupId = null,
        /** @var list<string> */
        public array $tagGroupIds = [],
    ) {
        self::assertIdentifier($id);
        self::assertLabel($label);
        self::assertInstitution($institution);
        self::assertProductModelId($productModelId);
        self::assertGrouping($primaryGroupId, $tagGroupIds);
        // Hydration of a row written before this rule still reaches here
        // without a group. Writes go through reconfigure/regroup/open and
        // refuse an included account that has none.

        if (null !== $productCode && null !== $productModelId) {
            throw new InvalidAccount('An account references at most one product or model.');
        }

        if (!$valuationMode->acceptsKind($kind)) {
            throw new InvalidAccount('A portfolio valuation requires an account kind that holds positions.');
        }

        if ($includeInEmergencyFund && !$includeInNetWorth) {
            throw new InvalidAccount('An account excluded from net worth cannot be part of the emergency fund.');
        }

        self::assertLifecycle($openedOn, $closedOn, $createdAt, $updatedAt);

        if ($version < 1) {
            throw new InvalidAccount('An account version must be positive.');
        }
    }

    /**
     * The account currency and its identity are deliberately absent: a
     * denomination change would silently reinterpret every figure already
     * recorded against the account, so it is a migration of data rather than an
     * edit.
     *
     * @param list<string> $tagGroupIds
     */
    public function reconfigure(
        string $label,
        AccountKind $kind,
        ?ProductCode $productCode,
        ?string $productModelId,
        ?string $institution,
        ?MaskedIdentifier $maskedIdentifier,
        AccountValuationMode $valuationMode,
        LiquidityLevel $liquidityLevel,
        bool $includeInNetWorth,
        bool $includeInEmergencyFund,
        \DateTimeImmutable $openedOn,
        ?\DateTimeImmutable $closedOn,
        \DateTimeImmutable $updatedAt,
        ?string $primaryGroupId = null,
        array $tagGroupIds = [],
        bool $keepGrouping = true,
    ): self {
        $this->assertWritable();

        if (null !== $this->usedAt && $kind !== $this->kind) {
            throw new InvalidAccount('A used account cannot change kind.');
        }

        // Swapping the product or the model of an account that already carries
        // history would re-read every past movement against another set of
        // dated rules — a Livret A ceiling becoming a PEA contribution
        // ceiling, retroactively.
        if (null !== $this->usedAt && !self::sameProduct($this->productCode, $productCode)) {
            throw new InvalidAccount('A used account cannot change product.');
        }

        if (null !== $this->usedAt && $this->productModelId !== $productModelId) {
            throw new InvalidAccount('A used account cannot change model.');
        }

        if (!$keepGrouping) {
            self::assertIncludedHasPrimaryGroup($includeInNetWorth, $primaryGroupId);
        } elseif ($includeInNetWorth && !$this->includeInNetWorth) {
            self::assertIncludedHasPrimaryGroup(true, $this->primaryGroupId);
        }

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            label: trim($label),
            assetCode: $this->assetCode,
            kind: $kind,
            productCode: $productCode,
            productModelId: $productModelId,
            institution: $institution,
            maskedIdentifier: $maskedIdentifier,
            valuationMode: $valuationMode,
            liquidityLevel: $liquidityLevel,
            includeInNetWorth: $includeInNetWorth,
            includeInEmergencyFund: $includeInEmergencyFund,
            openedOn: $openedOn,
            closedOn: $closedOn,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
            usedAt: $this->usedAt,
            archivedAt: $this->archivedAt,
            primaryGroupId: $keepGrouping ? $this->primaryGroupId : $primaryGroupId,
            tagGroupIds: $keepGrouping ? $this->tagGroupIds : $tagGroupIds,
        );
    }

    /**
     * Exclusive membership is the primary group; tags are extra labels that
     * never join that exclusive weight. Clearing the primary group leaves the
     * account ungrouped, the way it is created.
     *
     * @param list<string> $tagGroupIds
     */
    public function regroup(
        ?string $primaryGroupId,
        array $tagGroupIds,
        \DateTimeImmutable $updatedAt,
    ): self {
        $this->assertWritable();
        self::assertIncludedHasPrimaryGroup($this->includeInNetWorth, $primaryGroupId);

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            label: $this->label,
            assetCode: $this->assetCode,
            kind: $this->kind,
            productCode: $this->productCode,
            productModelId: $this->productModelId,
            institution: $this->institution,
            maskedIdentifier: $this->maskedIdentifier,
            valuationMode: $this->valuationMode,
            liquidityLevel: $this->liquidityLevel,
            includeInNetWorth: $this->includeInNetWorth,
            includeInEmergencyFund: $this->includeInEmergencyFund,
            openedOn: $this->openedOn,
            closedOn: $this->closedOn,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
            usedAt: $this->usedAt,
            archivedAt: $this->archivedAt,
            primaryGroupId: $primaryGroupId,
            tagGroupIds: $tagGroupIds,
        );
    }

    /**
     * Archiving is how an account leaves the working set. Deletion is not
     * offered: an account referenced by history must keep answering for it.
     */
    public function archive(\DateTimeImmutable $archivedAt): self
    {
        $this->assertWritable();

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            label: $this->label,
            assetCode: $this->assetCode,
            kind: $this->kind,
            productCode: $this->productCode,
            productModelId: $this->productModelId,
            institution: $this->institution,
            maskedIdentifier: $this->maskedIdentifier,
            valuationMode: $this->valuationMode,
            liquidityLevel: $this->liquidityLevel,
            includeInNetWorth: $this->includeInNetWorth,
            includeInEmergencyFund: $this->includeInEmergencyFund,
            openedOn: $this->openedOn,
            closedOn: $this->closedOn,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $archivedAt,
            usedAt: $this->usedAt,
            archivedAt: $archivedAt,
            primaryGroupId: $this->primaryGroupId,
            tagGroupIds: $this->tagGroupIds,
        );
    }

    public function isClosed(): bool
    {
        return null !== $this->closedOn;
    }

    /**
     * A recorded snapshot is history: the kind and product can no longer
     * change without reinterpreting that figure. A second snapshot does not
     * bump the version again — the lock is already on.
     */
    public function markUsed(\DateTimeImmutable $usedAt): self
    {
        if (null !== $this->usedAt) {
            return $this;
        }

        $this->assertWritable();

        return new self(
            id: $this->id,
            workspace: $this->workspace,
            label: $this->label,
            assetCode: $this->assetCode,
            kind: $this->kind,
            productCode: $this->productCode,
            productModelId: $this->productModelId,
            institution: $this->institution,
            maskedIdentifier: $this->maskedIdentifier,
            valuationMode: $this->valuationMode,
            liquidityLevel: $this->liquidityLevel,
            includeInNetWorth: $this->includeInNetWorth,
            includeInEmergencyFund: $this->includeInEmergencyFund,
            openedOn: $this->openedOn,
            closedOn: $this->closedOn,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $usedAt,
            usedAt: $usedAt,
            archivedAt: $this->archivedAt,
            primaryGroupId: $this->primaryGroupId,
            tagGroupIds: $this->tagGroupIds,
        );
    }

    /**
     * The sign this account contributes to net worth. A liability holds a
     * positive outstanding amount and reduces net worth by it, which keeps the
     * stored figure readable while the aggregation stays exact.
     */
    public function netWorthSign(): int
    {
        return AccountKind::LIABILITY === $this->kind ? -1 : 1;
    }

    private function assertWritable(): void
    {
        if (null !== $this->archivedAt) {
            throw new InvalidAccount('An archived account is read-only.');
        }
    }

    private static function assertIdentifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidAccount('An account identifier must be a canonical UUID.');
        }
    }

    private static function assertProductModelId(?string $productModelId): void
    {
        if (null === $productModelId) {
            return;
        }

        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $productModelId)) {
            throw new InvalidAccount('A product model reference must be a canonical UUID.');
        }
    }

    /**
     * Exclusive allocation needs one primary group per included account.
     * An excluded account may stay ungrouped.
     */
    public static function assertIncludedHasPrimaryGroup(bool $includeInNetWorth, ?string $primaryGroupId): void
    {
        if ($includeInNetWorth && null === $primaryGroupId) {
            throw new InvalidAccount('An account included in net worth must belong to one primary group.');
        }
    }

    /**
     * @param list<string> $tagGroupIds
     */
    private static function assertGrouping(?string $primaryGroupId, array $tagGroupIds): void
    {
        if (null !== $primaryGroupId) {
            self::assertIdentifier($primaryGroupId);
        }

        $seen = [];
        foreach ($tagGroupIds as $tagGroupId) {
            self::assertIdentifier($tagGroupId);
            if ($tagGroupId === $primaryGroupId) {
                throw new InvalidAccount('A tag cannot repeat the primary group.');
            }

            if (isset($seen[$tagGroupId])) {
                throw new InvalidAccount('A group tag cannot be assigned twice.');
            }

            $seen[$tagGroupId] = true;
        }
    }

    private static function sameProduct(?ProductCode $current, ?ProductCode $candidate): bool
    {
        if (null === $current || null === $candidate) {
            return null === $current && null === $candidate;
        }

        return $current->equals($candidate);
    }

    /**
     * The institution is free text the holder recognises their account by, so
     * it is bounded and trimmed like the label rather than validated against a
     * list: no reference of banks and insurers ships with the application, and
     * inventing one would refuse a legitimate name.
     */
    private static function assertInstitution(?string $institution): void
    {
        if (null === $institution) {
            return;
        }

        if ($institution !== trim($institution) || '' === $institution || mb_strlen($institution) > self::MAX_INSTITUTION_LENGTH) {
            throw new InvalidAccount(sprintf('An account institution must contain between 1 and %d characters.', self::MAX_INSTITUTION_LENGTH));
        }

        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $institution)) {
            throw new InvalidAccount('An account institution cannot contain control characters.');
        }
    }

    private static function assertLabel(string $label): void
    {
        if ($label !== trim($label) || '' === $label || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidAccount(sprintf('An account label must contain between 1 and %d characters.', self::MAX_LABEL_LENGTH));
        }

        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $label)) {
            throw new InvalidAccount('An account label cannot contain control characters.');
        }
    }

    /**
     * Lifecycle dates are checked against the aggregate's own timestamps rather
     * than a clock: the day of the last write is the entity's own notion of
     * "now", so neither date can be set in the future. The reference is the
     * later of creation and update, not creation alone — a correction made
     * months later must still be able to record a past opening date the
     * paperwork revealed.
     */
    private static function assertLifecycle(
        \DateTimeImmutable $openedOn,
        ?\DateTimeImmutable $closedOn,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ): void {
        self::assertBusinessDay($openedOn, 'opening date');

        if ($openedOn < new \DateTimeImmutable(self::MIN_OPENED_ON, new \DateTimeZone('UTC'))) {
            throw new InvalidAccount(sprintf('An account cannot be opened before %s.', self::MIN_OPENED_ON));
        }

        $today = max(self::utcDay($createdAt), self::utcDay($updatedAt));

        if (self::utcDay($openedOn) > $today) {
            throw new InvalidAccount('An account cannot be opened in the future.');
        }

        if (null === $closedOn) {
            return;
        }

        self::assertBusinessDay($closedOn, 'closing date');

        if ($closedOn < $openedOn) {
            throw new InvalidAccount('An account cannot be closed before it was opened.');
        }

        if (self::utcDay($closedOn) > $today) {
            throw new InvalidAccount('An account cannot be closed in the future.');
        }
    }

    private static function assertBusinessDay(\DateTimeImmutable $date, string $subject): void
    {
        if ('00:00:00.000000' !== $date->format('H:i:s.u') || 0 !== $date->getOffset()) {
            throw new InvalidAccount(sprintf('An account %s is a UTC calendar day.', $subject));
        }
    }

    /**
     * Calendar days are compared in UTC on both sides, the way the database
     * constraint does. Reading a timestamp in the server timezone instead would
     * make the entity and the schema disagree for a few hours a day.
     */
    private static function utcDay(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }
}
