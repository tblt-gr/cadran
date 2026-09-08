<?php

declare(strict_types=1);

namespace App\Module\Categories\UI\Http;

use App\Module\Categories\Application\CategoryImpactView;
use App\Module\Categories\Application\CategoryPage;
use App\Module\Categories\Application\CategoryReplacementView;
use App\Module\Categories\Application\CategoryView;

/**
 * The wire shape of a category, kept apart from the controller that serves it.
 *
 * A view is an application-layer object free to gain fields the contract does
 * not publish; this class is the single place that decides what actually leaves
 * the API, so a new view field never reaches a client by accident.
 */
final readonly class CategoryRepresentation
{
    /** @return array<string, mixed> */
    public static function one(CategoryView $category): array
    {
        return [
            'id' => $category->id,
            'type' => $category->type,
            'label' => $category->label,
            'parentId' => $category->parentId,
            'parentLabel' => $category->parentLabel,
            'icon' => $category->icon,
            'color' => $category->color,
            'defaultAnalyticAxes' => $category->defaultAnalyticAxes,
            'budgetIncluded' => $category->budgetIncluded,
            'sortOrder' => $category->sortOrder,
            'depth' => $category->depth,
            'version' => $category->version,
            'used' => $category->used,
            'typeEditable' => $category->typeEditable,
            'typeEditReason' => $category->typeEditReason,
            'canAcceptChildren' => $category->canAcceptChildren,
            'archivedAt' => $category->archivedAt,
            'replacement' => self::replacement($category->replacement),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function replacement(?CategoryReplacementView $replacement): ?array
    {
        if (null === $replacement) {
            return null;
        }

        return [
            'kind' => $replacement->kind,
            'targetId' => $replacement->targetId,
            'targetLabel' => $replacement->targetLabel,
            'effectiveFrom' => $replacement->effectiveFrom,
        ];
    }

    /**
     * The impact of an operation that has not happened.
     *
     * `affectedClassifications` counts the splits a merge or replacement would
     * re-point. When the count cannot be established it stays null and
     * `affectedClassificationsReason` says why, never a guessed zero.
     *
     * @return array<string, mixed>
     */
    public static function impact(CategoryImpactView $impact): array
    {
        return [
            'operation' => $impact->operation,
            'categoryId' => $impact->categoryId,
            'targetId' => $impact->targetId,
            'targetLabel' => $impact->targetLabel,
            'effectiveFrom' => $impact->effectiveFrom,
            'descendantCount' => $impact->descendantCount,
            'archivedDescendantCount' => $impact->archivedDescendantCount,
            'reparentedChildCount' => $impact->reparentedChildCount,
            'incomingRedirectionCount' => $impact->incomingRedirectionCount,
            'resultingDepth' => $impact->resultingDepth,
            'maximumDepth' => $impact->maximumDepth,
            'archivesSource' => $impact->archivesSource,
            'redirectsHistory' => $impact->redirectsHistory,
            'allowed' => $impact->allowed,
            'blockers' => $impact->blockers,
            'affectedClassifications' => $impact->affectedClassifications,
            'affectedClassificationsReason' => $impact->affectedClassificationsReason,
        ];
    }

    /** @return array<string, mixed> */
    public static function page(CategoryPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ];
    }
}
