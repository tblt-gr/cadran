<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\InvalidProductModelInput;
use App\Module\Accounts\Application\ProductModelArchived;
use App\Module\Accounts\Application\ProductModelConflict;
use App\Module\Accounts\Application\ProductModelNotFound;
use App\Module\Accounts\Application\StaleProductModelVersion;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ProblemRule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each exception of the product-model endpoints becomes.
 *
 * The first matching row wins, so a subclass must precede its parent; the stale version row
 * stays ahead of the name conflict so that remains true if one ever extends the other.
 */
final class ProductModelProblems
{
    /**
     * A name already taken and a stale version are both 409, but they ask the
     * caller for opposite things: pick another name, or reload and reapply.
     * The problem type is what lets a client tell them apart.
     */
    public const string TYPE_NAME_TAKEN = '/problems/product-model-name-taken';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/product-model-archived';

    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function read(): array
    {
        return [
            ProductModelNotFound::class => self::notFound(),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function list(): array
    {
        return [
            InvalidProductModelInput::class => new ProblemRule(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_product_model_query'),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    /**
     * Creating, copying, dating a period and archiving.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function write(): array
    {
        $invalid = new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_product_model');

        return [
            ProductModelArchived::class => new ProblemRule(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.product_model_archived', self::TYPE_ARCHIVED),
            InvalidProductModelInput::class => $invalid,
            \UnexpectedValueException::class => $invalid,
            ProductModelNotFound::class => self::notFound(),
            StaleProductModelVersion::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.product_model_stale_version', self::TYPE_STALE_VERSION),
            ProductModelConflict::class => new ProblemRule(Response::HTTP_CONFLICT, 'api.problem.product_model_name_taken', self::TYPE_NAME_TAKEN),
            WorkspaceAccessDenied::class => self::forbidden(),
        ];
    }

    private static function notFound(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
    }

    private static function forbidden(): ProblemRule
    {
        return new ProblemRule(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
    }
}
