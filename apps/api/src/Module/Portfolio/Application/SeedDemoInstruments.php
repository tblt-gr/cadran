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
            ['iShares Core MSCI World UCITS ETF USD (Acc)', 'IE00B4L5Y983', 'IWDA', 'XAMS', 'ETF', 'EQUITY', 'WORLD', false],
            ['Vanguard FTSE All-World UCITS ETF USD Acc', 'IE00BK5BQT80', 'VWCE', 'XETR', 'ETF', 'EQUITY', 'WORLD', false],
            ['Amundi MSCI World UCITS ETF EUR (C)', 'LU1681043599', 'CW8', 'XPAR', 'ETF', 'EQUITY', 'WORLD', false],
            ['Amundi MSCI Emerging Markets UCITS ETF EUR (C)', 'LU1681045370', 'AEEM', 'XPAR', 'ETF', 'EQUITY', 'EMERGING', false],
            ['iShares Core EUR Corporate Bond UCITS ETF', 'IE00B3F81R35', 'IEAC', 'XAMS', 'ETF', 'BOND', 'EUROPE', false],
            ['Invesco Physical Gold ETC', 'IE00B579F325', null, null, 'OTHER', 'COMMODITY', 'WORLD', false],
            ['TotalEnergies SE', 'FR0000120271', 'TTE', 'XPAR', 'EQUITY', 'EQUITY', 'FRANCE', false],
            ['LVMH Moët Hennessy Louis Vuitton', 'FR0000121014', 'MC', 'XPAR', 'EQUITY', 'EQUITY', 'FRANCE', false],
            ['Air Liquide', 'FR0000120073', 'AI', 'XPAR', 'EQUITY', 'EQUITY', 'FRANCE', false],
            ['Sanofi', 'FR0000120578', 'SAN', 'XPAR', 'EQUITY', 'EQUITY', 'FRANCE', false],
            ['Vanguard S&P 500 UCITS ETF USD Dist', 'IE00B3XXRP09', 'VUSA', 'XAMS', 'ETF', 'EQUITY', 'NORTH_AMERICA', false],
            ['Danone', 'FR0000120644', 'BN', 'XPAR', 'EQUITY', 'EQUITY', 'FRANCE', false],
            ['Lyxor MSCI World UCITS ETF C-EUR (fusionné)', 'FR0010315770', null, null, 'ETF', 'EQUITY', 'WORLD', true],
            // The instruments the demo portfolio operations are recorded on; the last one has no class nor region on purpose.
            ['ETF Monde Démo', 'FRDEMO000015', 'DMWLD', 'XPAR', 'ETF', 'EQUITY', 'WORLD', false],
            ['Action Démo Industrie', 'FRDEMO000023', 'DMIND', 'XPAR', 'EQUITY', 'EQUITY', 'FRANCE', false],
            ['Fonds Obligataire Démo', 'FRDEMO000031', null, null, 'FUND', 'BOND', 'EUROPE', false],
            ['Instrument sans classe Démo', 'FRDEMO000049', null, null, 'OTHER', null, null, false],
            ['Ancien fonds Démo', 'FRDEMO000056', null, null, 'FUND', 'MIXED', 'EUROPE', true],
        ] as [$name, $isin, $ticker, $venue, $type, $class, $region, $closed]) {
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
            if ($closed) {
                $this->deactivateInstrument->__invoke($instrument->id, $instrument->version);
            }
        }
    }
}
