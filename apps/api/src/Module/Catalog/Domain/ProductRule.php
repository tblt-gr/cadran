<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * One dated rule of one product, with the publication it was read from and the
 * trace of who last checked it against that publication.
 */
final readonly class ProductRule
{
    /**
     * French regulated rates are revised twice a year, on 1 February and
     * 1 August. A value nobody has re-read across a whole revision cycle may
     * already have been superseded, so it is shown as stale rather than as
     * current fact.
     */
    public const string REVIEW_INTERVAL = 'P6M';

    public const int MAX_VERIFIER_LENGTH = 80;

    public function __construct(
        public RuleKind $kind,
        public RuleValue $value,
        public EffectivePeriod $period,
        public CatalogSource $source,
        public ?\DateTimeImmutable $verifiedOn,
        public ?string $verifiedBy,
    ) {
        if ($kind->valueType() !== $value->type) {
            throw new InvalidCatalogEntry(sprintf('A %s rule carries a %s value.', $kind->value, $kind->valueType()->value));
        }

        if ((null === $verifiedOn) !== (null === $verifiedBy)) {
            // Half a verification trace is worse than none: it would show a
            // date nobody stands behind, or a name with nothing to date it.
            throw new InvalidCatalogEntry('A rule verification carries both its date and its verifier.');
        }

        if (null !== $verifiedBy && ($verifiedBy !== trim($verifiedBy) || '' === $verifiedBy || mb_strlen($verifiedBy) > self::MAX_VERIFIER_LENGTH)) {
            throw new InvalidCatalogEntry(sprintf('A rule verifier contains between 1 and %d characters.', self::MAX_VERIFIER_LENGTH));
        }
    }

    /**
     * The bracket scale this rule states, or null when it states no rate. A
     * catalogue row carries one published percentage, which resolves to the
     * single bracket covering the whole balance; consumers read every rate
     * through the same scale whatever the product turns out to be.
     */
    public function rateScale(): ?RateScale
    {
        $percentage = $this->value->percentage;

        return null === $percentage ? null : RateScale::wholeBalance($percentage);
    }

    /**
     * How far the recorded verification is from $today. The business date the
     * rule applies to plays no part here: an old rule read against an old date
     * is right, while a value left unchecked for a revision cycle is doubtful
     * whatever period it covers.
     */
    public function verificationOn(\DateTimeImmutable $today): VerificationState
    {
        if (null === $this->verifiedOn) {
            return VerificationState::UNVERIFIED;
        }

        if ($this->verifiedOn->add(new \DateInterval(self::REVIEW_INTERVAL)) < $today) {
            return VerificationState::STALE;
        }

        return VerificationState::VERIFIED;
    }
}
