<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

final readonly class GetFoundationStatus
{
    private const string UNKNOWN_VERSION = 'unknown';

    /**
     * @param string $version release the image was built from (CADRAN_VERSION build argument)
     */
    public function __construct(private string $version = self::UNKNOWN_VERSION)
    {
    }

    public function __invoke(): FoundationStatus
    {
        $version = trim($this->version);

        return new FoundationStatus(
            status: 'ready',
            apiVersion: 'v1',
            version: '' === $version ? self::UNKNOWN_VERSION : $version,
        );
    }
}
