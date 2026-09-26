<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final class MetricPolicyAuditEvents
{
    public const string CREATED = 'metric_policy.created';
    public const string ACTIVATED = 'metric_policy.activated';
    public const string ENTITY = 'metric_policy';
}
