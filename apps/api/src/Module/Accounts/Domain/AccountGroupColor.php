<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/** Stable presentation defaults derived from the immutable UUID, never list position. */
final class AccountGroupColor
{
    /** Lightness targets, consumed by color slot. */
    private const array LIGHTNESS_SEQUENCE = [0.72, 0.44, 0.85, 0.30, 0.58, 0.79, 0.37, 0.65, 0.51];

    public const array PALETTE = ['#486DF0', '#803078', '#58B8C0', '#F0A8D8'];

    public static function normalize(?string $color): ?string
    {
        if (!self::isValid($color)) {
            throw new InvalidAccountGroup('A group color must be null or a six-digit hexadecimal color.');
        }

        return null === $color ? null : strtoupper($color);
    }

    public static function isValid(mixed $color): bool
    {
        return null === $color || (is_string($color) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/D', $color));
    }

    public static function defaultFor(string $id): string
    {
        // Two hash digits keep the integer bounded on every supported platform.
        return self::PALETTE[intval(substr(hash('sha256', $id), 0, 2), 16) % count(self::PALETTE)];
    }

    /**
     * Same hue and saturation as the parent, lightness picked by stable slot.
     * Presentation arithmetic only: floats never reach a financial figure.
     */
    public static function shade(string $hex, int $slot): string
    {
        if (1 !== preg_match('/^#[0-9A-F]{6}$/D', $hex)) {
            throw new InvalidAccountGroup('A shaded color needs a canonical hexadecimal parent color.');
        }

        [$hue, $saturation, $lightness] = self::toHsl($hex);
        $usable = array_values(array_filter(
            self::LIGHTNESS_SEQUENCE,
            static fn (float $target): bool => abs($target - $lightness) >= 0.06,
        ));
        if ([] === $usable) {
            $usable = self::LIGHTNESS_SEQUENCE;
        }

        return self::fromHsl($hue, $saturation, $usable[max(0, $slot) % count($usable)]);
    }

    /** @return array{float, float, float} */
    private static function toHsl(string $hex): array
    {
        $red = hexdec(substr($hex, 1, 2)) / 255;
        $green = hexdec(substr($hex, 3, 2)) / 255;
        $blue = hexdec(substr($hex, 5, 2)) / 255;
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;
        if ($delta <= 0.0) {
            return [0.0, 0.0, $lightness];
        }

        $saturation = $delta / (1 - abs(2 * $lightness - 1));
        $hue = match ($max) {
            $red => fmod(($green - $blue) / $delta + 6, 6),
            $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        } * 60;

        return [$hue, $saturation, $lightness];
    }

    private static function fromHsl(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $channel = static function (float $offset) use ($hue, $lightness, $chroma): int {
            $k = fmod($offset + $hue / 30, 12);
            $value = $lightness - $chroma / 2 * max(-1.0, min($k - 3, 9 - $k, 1.0));

            return (int) floor($value * 255 + 0.5);
        };

        return sprintf('#%02X%02X%02X', $channel(0), $channel(8), $channel(4));
    }
}
