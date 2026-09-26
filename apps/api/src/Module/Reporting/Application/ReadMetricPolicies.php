<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\MetricPolicy;
use App\Module\Reporting\Domain\MetricPolicyRepository;
use App\Module\Reporting\Domain\MetricPolicyResolver;

final readonly class ReadMetricPolicies
{
    public function __construct(
        private CallerWorkspace $caller,
        private MetricPolicyRepository $policies,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(): MetricPolicyCatalogView
    {
        return self::catalog($this->caller->resolve(), $this->policies, $this->calendar->now());
    }

    public static function catalog(WorkspaceScope $workspace, MetricPolicyRepository $policies, \DateTimeImmutable $now): MetricPolicyCatalogView
    {
        $activations = $policies->activations($workspace);
        $version = MetricPolicyResolver::forMonth(null, $now, $activations);
        $since = null;
        foreach ($activations as $activation) {
            if ($activation->activeFrom <= $now && $activation->policyVersion === $version && (null === $since || $activation->activeFrom > $since)) {
                $since = $activation->activeFrom;
            }
        }
        $stored = $policies->listStored($workspace);
        $active = $policies->find($workspace, $version);

        return new MetricPolicyCatalogView(
            $version,
            null === $active ? null : MetricPolicyView::of($active),
            $since?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            array_map(MetricPolicyView::of(...), [MetricPolicy::systemV1(), ...$stored]),
        );
    }
}
