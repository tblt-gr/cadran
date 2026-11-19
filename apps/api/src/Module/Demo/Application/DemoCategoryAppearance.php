<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

final class DemoCategoryAppearance
{
    /** key => [icon, color]. Icons come from the web catalogue (category-icons.ts); colors are distinct so charts read at a glance. */
    private const array APPEARANCE = [
        'salary' => ['briefcase', '#4CAF7D'],
        'salary_bonus' => ['star', '#8BC34A'],
        'rent' => ['home', '#5C8DEF'],
        'energy' => ['bulb', '#F2B84B'],
        'telecom' => ['wifi', '#38B2C8'],
        'subscriptions' => ['repeat', '#9B7BEA'],
        'groceries' => ['shopping-cart', '#E8833A'],
        'restaurants' => ['utensils', '#E5604D'],
        'transport' => ['bus', '#4A9BD9'],
        'health' => ['medical-cross', '#D9577E'],
        'leisure' => ['ticket', '#C26FD3'],
        'shopping' => ['bag', '#E0A458'],
        'holidays' => ['plane', '#3FB8A5'],
    ];

    /** @return array{0: string, 1: string} icon and color of a demo category key */
    public static function of(string $key): array
    {
        return self::APPEARANCE[$key];
    }
}
