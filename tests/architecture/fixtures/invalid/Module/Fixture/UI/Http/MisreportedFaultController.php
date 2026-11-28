<?php

declare(strict_types=1);

namespace App\Module\Fixture\UI\Http;

/** Deliberately invalid: the boundary check must reject both. */
final class MisreportedFaultController
{
    public function update(): int
    {
        try {
            return 200;
        } catch (\UnexpectedValueException) {
            return $this->problem();
        }
    }

    private function problem(): int
    {
        return 422;
    }
}
