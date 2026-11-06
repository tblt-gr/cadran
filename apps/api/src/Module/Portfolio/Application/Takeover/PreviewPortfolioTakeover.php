<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverBlockReason;

/** What a takeover would be worth, line by line, and every problem of the request. It writes nothing and takes no lock. */
final readonly class PreviewPortfolioTakeover
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private PortfolioTakeoverEligibility $eligibility,
        private TakeoverResolver $resolver,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $accountId, TakeoverInput $input): PortfolioTakeoverPreview
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->accounts->find($workspace, $accountId) ?? throw new PortfolioAccountNotFound();
        $today = $this->calendar->today();
        if (PortfolioTakeoverBlockReason::NOT_SUPPORTED === $this->eligibility->blockingReason($account, $account->openedOn->modify('-1 day'))) {
            throw new PortfolioTakeoverRefused(PortfolioTakeoverBlockReason::NOT_SUPPORTED);
        }
        $resolved = $this->resolver->resolve($account, $input, $today);
        $precision = $resolved->displayPrecision;
        $errors = $resolved->errors;

        $lines = [];
        $values = [];
        $complete = [] !== $resolved->lines;
        foreach ($resolved->lines as $line) {
            $unitCost = null;
            if (null !== $line->costBasis && null !== $line->quantity && null !== $line->instrument) {
                $unitCost = self::padded(ExactDecimal::divide($line->costBasis, $line->quantity, $line->instrument->pricePrecision, RoundingMode::HALF_EVEN), $line->instrument->pricePrecision);
            }
            $lines[] = new TakeoverPreviewLine($line->instrumentId, null === $line->value ? null : self::padded($line->value, $precision), $unitCost);
            if (null === $line->value) {
                $complete = false;
            } else {
                $values[] = $line->value;
            }
        }

        $securities = null;
        $total = null;
        if ($complete) {
            try {
                $sum = ExactDecimal::sum(...$values);
                $securities = self::padded($sum, $precision);
                $total = self::padded(ExactDecimal::add($sum, $resolved->cash ?? DecimalValue::zero()), $precision);
            } catch (PrecisionExceeded) {
                $errors[] = new TakeoverError(null, 'lines', 'value_too_large');
            }
        }

        return new PortfolioTakeoverPreview($lines, $securities, null === $resolved->cash ? '0' : self::padded($resolved->cash, $precision), $total, $errors);
    }

    /** A figure shown with at least the given number of decimals, never rounded. */
    private static function padded(DecimalValue $value, int $scale): string
    {
        return $value->scale() < $scale ? ExactDecimal::round($value, $scale, RoundingMode::HALF_EVEN)->toString() : $value->toString();
    }
}
