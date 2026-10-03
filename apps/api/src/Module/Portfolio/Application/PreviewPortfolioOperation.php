<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;

/** The figures the server would derive for a create body, without writing anything. */
final readonly class PreviewPortfolioOperation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private OperationResolver $resolver,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $accountId, OperationInput $input): OperationPreview
    {
        $context = $this->caller->resolveContext();
        $account = $this->gate->forWrite($context->workspace, $accountId, false);
        $resolved = $this->resolver->resolve($account, $input, $this->calendar->today(), false);
        $asset = $account->assetCode->toString();
        $amount = static fn (string $value): array => ['value' => $value, 'assetCode' => $asset];

        return new OperationPreview(
            $amount($resolved->effect->cashAmount->toString()),
            null === $resolved->effect->roundingResidual ? null : $amount($resolved->effect->roundingResidual->toString()),
            null === $resolved->effect->expectedGrossAmount ? null : $amount($resolved->effect->expectedGrossAmount->toString()),
        );
    }
}
