<?php

declare(strict_types=1);

namespace App\Module\Categories\UI\Http;

use App\Module\Categories\Application\CategoryPage;
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
