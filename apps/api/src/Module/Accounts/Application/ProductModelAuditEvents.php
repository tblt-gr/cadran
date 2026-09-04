<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final class ProductModelAuditEvents
{
    public const string CREATED = 'product_model.created';
    public const string DUPLICATED = 'product_model.duplicated';
    public const string RULE_RECORDED = 'product_model.rule_recorded';
    public const string ARCHIVED = 'product_model.archived';
    public const string ENTITY = 'product_model';
}
