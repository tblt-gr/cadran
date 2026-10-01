<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\DemoDataContributor;

final readonly class SeedDemoInstruments implements DemoDataContributor
{
    public function __construct(
        private CreateInstrument $createInstrument,
        private DeactivateInstrument $deactivateInstrument,
    ) {
    }

    public function seed(): void
    {
        foreach ([
            ['ETF Monde Démo', 'FRDEMO000015', 'DMWLD', 'XPAR', 'ETF', 'EQUITY', 'WORLD'],
            ['Action Démo Industrie', 'FRDEMO000023', 'DMIND', 'XPAR', 'EQUITY', 'EQUITY', 'FRANCE'],
            ['Fonds Obligataire Démo', 'FRDEMO000031', null, null, 'FUND', 'BOND', 'EUROPE'],
            ['Instrument sans classe Démo', 'FRDEMO000049', null, null, 'OTHER', null, null],
            ['Ancien fonds Démo', 'FRDEMO000056', null, null, 'FUND', 'MIXED', 'EUROPE'],
        ] as $index => [$name, $isin, $ticker, $venue, $type, $class, $region]) {
            $instrument = $this->createInstrument->__invoke(new CreateInstrumentInput(
                name: $name,
                isin: $isin,
                ticker: $ticker,
                venueMic: $venue,
                instrumentType: $type,
                assetClass: $class,
                region: $region,
                currencyCode: 'EUR',
            ));
            if (4 === $index) {
                $this->deactivateInstrument->__invoke($instrument->id, $instrument->version);
            }
        }
    }
}
