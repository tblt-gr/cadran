<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\InvalidProductModelInput;
use App\Module\Accounts\Application\ProductModelArchived;
use App\Module\Accounts\Application\ProductModelConflict;
use App\Module\Accounts\Application\ProductModelNotFound;
use App\Module\Accounts\Application\StaleProductModelVersion;
use App\Module\Accounts\UI\Http\ProductModelProblems;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\MalformedPayload;

final class ProductModelProblemsTest extends ProblemTableTestCase
{
    public static function tables(): iterable
    {
        $forbidden = [new WorkspaceAccessDenied(), 403, 'api.problem.product_model_forbidden'];
        $notFound = [new ProductModelNotFound(), 404, 'api.problem.product_model_not_found'];

        yield 'read' => [ProductModelProblems::read(), [$notFound, $forbidden]];
        yield 'list' => [ProductModelProblems::list(), [
            [new InvalidProductModelInput(), 400, 'api.problem.invalid_product_model_query'],
            $forbidden,
        ]];
        yield 'write' => [ProductModelProblems::write(), [
            [new ProductModelArchived(), 422, 'api.problem.product_model_archived', '/problems/product-model-archived'],
            [new InvalidProductModelInput(), 422, 'api.problem.invalid_product_model'],
            [new MalformedPayload(), 422, 'api.problem.invalid_product_model'],
            $notFound,
            [new StaleProductModelVersion(), 409, 'api.problem.product_model_stale_version', '/problems/stale-version'],
            [new ProductModelConflict(), 409, 'api.problem.product_model_name_taken', '/problems/product-model-name-taken'],
            $forbidden,
        ]];
    }
}
