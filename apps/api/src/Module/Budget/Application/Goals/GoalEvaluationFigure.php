<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Goals;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** A target or an achieved value: an amount in the goal's asset, or a ratio with its percent display. */
final readonly class GoalEvaluationFigure
{
    private function __construct(
        public ?string $value,
        public ?string $assetCode,
        public ?string $ratio,
        public ?string $percentDisplay,
    ) {
    }

    public static function amount(string $value, string $assetCode): self
    {
        return new self($value, $assetCode, null, null);
    }

    public static function rate(string $ratio): self
    {
        return new self(null, null, $ratio, (string) BigDecimal::of($ratio)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp));
    }

    public function isRate(): bool
    {
        return null !== $this->ratio;
    }
}
