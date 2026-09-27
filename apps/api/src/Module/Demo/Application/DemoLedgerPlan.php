<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

/**
 * The deterministic monthly life of a French household: one salary, fixed
 * charges, variable spending, a holiday in summer and a couple of refunds.
 */
final class DemoLedgerPlan
{
    /** Monthly budget targets by category key, in cents. */
    public const array BUDGET = [
        'rent' => 72000,
        'energy' => 9500,
        'telecom' => 6000,
        'subscriptions' => 5500,
        'groceries' => 45000,
        'restaurants' => 15000,
        'transport' => 16000,
        'health' => 6000,
        'leisure' => 8000,
        'shopping' => 12000,
    ];

    public function __construct(private readonly DemoRandom $random)
    {
    }

    /** @return list<DemoEntry> ordered by day */
    public function forMonth(int $month): array
    {
        $r = $this->random;
        $entries = [
            new DemoEntry(1, 'salary', 'VIR SALAIRE ACME SAS', 'Acme SAS', 245000 + $r->between(-4000, 6000), 'TRANSFER'),
            new DemoEntry(3, 'rent', 'PRLV LOYER RESIDENCE', 'Residence Les Lilas', -72000, 'DIRECT_DEBIT'),
            new DemoEntry(2, 'subscriptions', 'PRLV FITNESS CLUB', 'Fitness Club', -2990, 'DIRECT_DEBIT'),
            new DemoEntry(5, 'energy', 'PRLV ELECTRICITE', 'Energie Verte', -$r->between(5500, 9500), 'DIRECT_DEBIT'),
            new DemoEntry(5, 'telecom', 'PRLV FIBRE INTERNET', 'Fibre Net', -3999, 'DIRECT_DEBIT'),
            new DemoEntry(6, 'telecom', 'PRLV FORFAIT MOBILE', 'Mobile Plus', -1999, 'DIRECT_DEBIT'),
            new DemoEntry(8, 'subscriptions', 'PRLV STREAMING VIDEO', 'Streaming Video', -1399, 'DIRECT_DEBIT'),
            new DemoEntry(12, 'subscriptions', 'PRLV MUSIQUE', 'Musique en ligne', -1099, 'DIRECT_DEBIT'),
            new DemoEntry(1, 'transport', 'PASS TRANSPORT MENSUEL', 'Transports urbains', -8680, 'DIRECT_DEBIT'),
        ];

        foreach ([4, 10, 15, 21, 27] as $day) {
            $entries[] = new DemoEntry($day, 'groceries', 'CB SUPERMARCHE', 'Supermarche', -$r->between(3000, 12000), 'CARD');
        }
        foreach ([7 + $r->between(0, 2), 18 + $r->between(0, 3)] as $day) {
            $entries[] = new DemoEntry($day, 'restaurants', 'CB RESTAURANT', 'Restaurant', -$r->between(1800, 6500), 'CARD');
        }
        $entries[] = new DemoEntry(9 + $r->between(0, 3), 'transport', 'CB STATION SERVICE', 'Station service', -$r->between(4000, 7000), 'CARD');
        if (0 === $month % 2) {
            $entries[] = new DemoEntry(22, 'transport', 'CB STATION SERVICE', 'Station service', -$r->between(3500, 6500), 'CARD');
        }
        $entries[] = new DemoEntry(11 + $r->between(0, 5), 'health', 'CB PHARMACIE', 'Pharmacie', -$r->between(1200, 3500), 'CARD');
        if (0 === $month % 3) {
            $entries[] = new DemoEntry(16, 'health', 'CB CABINET MEDICAL', 'Cabinet medical', -2600, 'CARD');
        }
        $entries[] = new DemoEntry(13 + $r->between(0, 5), 'leisure', 'CB CINEMA', 'Cinema', -$r->between(1500, 4500), 'CARD');
        if (1 === $month % 2) {
            $entries[] = new DemoEntry(24, 'leisure', 'CB LOISIRS', 'Loisirs', -$r->between(2000, 5000), 'CARD');
        }

        return [...$entries, ...$this->occasional($month)];
    }

    /** @return list<DemoEntry> */
    private function occasional(int $month): array
    {
        $entries = match ($month) {
            // A refund must land in its original's month: a closed month refuses the write.
            3 => [
                new DemoEntry(19, 'shopping', 'CB BOUTIQUE VETEMENTS', 'Boutique', -9800, 'CARD', key: 'shopping-3'),
                new DemoEntry(26, 'shopping', 'REMBOURSEMENT BOUTIQUE', 'Boutique', 3500, 'CARD', refundsKey: 'shopping-3'),
            ],
            5 => [new DemoEntry(23, 'shopping', 'CB MAGASIN SPORT', 'Magasin sport', -11500, 'CARD')],
            6 => [new DemoEntry(14, 'salary_bonus', 'VIR PRIME ANNUELLE ACME SAS', 'Acme SAS', 90000, 'TRANSFER')],
            7 => [
                new DemoEntry(9, 'holidays', 'CB LOCATION VACANCES', 'Location vacances', -68000, 'CARD'),
                new DemoEntry(24, 'leisure', 'CB PARC LOISIRS', 'Parc de loisirs', -8400, 'CARD', key: 'leisure-7'),
                new DemoEntry(28, 'leisure', 'REMBOURSEMENT PARC LOISIRS', 'Parc de loisirs', 2100, 'CARD', refundsKey: 'leisure-7'),
            ],
            8 => [
                new DemoEntry(4, 'holidays', 'CB HOTEL LITTORAL', 'Hotel du Littoral', -45000, 'CARD'),
                new DemoEntry(2, 'holidays', 'CB BILLETS TRAIN', 'Voyages ferroviaires', -21000, 'CARD'),
            ],
            9 => [new DemoEntry(12, 'shopping', 'CB MAGASIN ELECTRONIQUE', 'Electronique', -8900, 'CARD')],
            default => [],
        };

        return $entries;
    }

    /** Savings and investment amounts moved out of the current account. */
    public function savingsCents(): int
    {
        return 25000 + 5000 * $this->random->between(0, 4);
    }

    public function investmentCents(): int
    {
        return 20000;
    }

    /** Per-mille market move applied to the investment account. */
    public function marketPerMille(): int
    {
        // Never zero: a flat month would leave nothing to adjust and no growth to show.
        return $this->random->between(-25, 40) ?: 3;
    }
}
