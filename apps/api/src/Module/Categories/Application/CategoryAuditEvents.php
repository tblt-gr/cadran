<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

final class CategoryAuditEvents
{
    public const string CREATED = 'category.created';
    public const string UPDATED = 'category.updated';
    public const string ENTITY = 'category';
}
