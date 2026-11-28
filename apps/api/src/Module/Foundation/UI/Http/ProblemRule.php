<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

/** How one exception class is answered: the status and the RFC 9457 document it becomes. */
final readonly class ProblemRule
{
    /**
     * @param array<string, mixed>  $extensions RFC 9457 extension members
     * @param array<string, string> $headers    extra response headers
     * @param array<string, mixed>  $parameters translation parameters of the detail
     */
    public function __construct(
        public int $status,
        public string $translationKey,
        public string $type = ApiProblem::TYPE_BLANK,
        public array $extensions = [],
        public array $headers = [],
        public array $parameters = [],
    ) {
    }
}
