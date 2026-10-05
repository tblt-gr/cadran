<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

/** What the client sent for the contract; the number is read once by the use case and dropped. */
final readonly class LifeInsuranceContractInput
{
    public function __construct(
        public string $insurer,
        public ?string $distributor,
        public ?string $commercialName,
        public bool $contractNumberProvided,
        #[\SensitiveParameter]
        public ?string $contractNumber,
        public string $subscribedOn,
        public ?string $taxStartOn,
        public string $managementMode,
        public ?int $version,
    ) {
    }
}
