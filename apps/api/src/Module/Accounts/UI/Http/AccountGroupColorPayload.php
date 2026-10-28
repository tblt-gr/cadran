<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Domain\AccountGroupColor;
use App\Module\Accounts\Domain\AccountGroupColorMode;

/** Strict reading of `colorMode` and `color` before anything reaches the domain. */
final readonly class AccountGroupColorPayload
{
    /** @param list<array{field: string, code: string}> $errors */
    private function __construct(public ?AccountGroupColorMode $mode, public array $errors)
    {
    }

    /** @param array<mixed> $body */
    public static function of(array $body): self
    {
        $errors = [];
        $mode = null;
        if (array_key_exists('colorMode', $body)) {
            $mode = is_string($body['colorMode']) ? AccountGroupColorMode::tryFrom($body['colorMode']) : null;
            if (null === $mode) {
                $errors[] = ['field' => 'colorMode', 'code' => 'invalid_choice'];
            }
        }

        $color = $body['color'] ?? null;
        $colorValid = AccountGroupColor::isValid($color);
        if (!$colorValid) {
            $errors[] = ['field' => 'color', 'code' => 'invalid_color'];
        }

        if (null !== $mode) {
            if (AccountGroupColorMode::PARENT === $mode && null === ($body['parentId'] ?? null)) {
                $errors[] = ['field' => 'colorMode', 'code' => 'parent_mode_on_root'];
            }
            if ($colorValid && AccountGroupColorMode::CUSTOM === $mode && null === $color) {
                $errors[] = ['field' => 'color', 'code' => 'required_for_custom'];
            }
            if ($colorValid && AccountGroupColorMode::CUSTOM !== $mode && null !== $color) {
                $errors[] = ['field' => 'color', 'code' => 'forbidden_unless_custom'];
            }
        }

        return new self($mode, $errors);
    }
}
