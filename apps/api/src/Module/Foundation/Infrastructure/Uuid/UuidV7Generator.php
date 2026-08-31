<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Uuid;

use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(UuidGenerator::class)]
final class UuidV7Generator implements UuidGenerator
{
    public function generate(): string
    {
        $milliseconds = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Uv');
        $random = random_bytes(10);
        $bytes = pack('Nn', $milliseconds >> 16, $milliseconds & 0xFFFF)
            .chr((ord($random[0]) & 0x0F) | 0x70)
            .$random[1]
            .chr((ord($random[2]) & 0x3F) | 0x80)
            .substr($random, 3, 7);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
