<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Portfolio\Domain\AssetClass;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentMetadata;
use App\Module\Portfolio\Domain\InstrumentRegion;
use App\Module\Portfolio\Domain\InstrumentSource;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\InvalidInstrument;
use App\Module\Portfolio\Domain\Isin;
use App\Module\Portfolio\Domain\Ticker;
use App\Module\Portfolio\Domain\VenueMic;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstrumentTest extends TestCase
{
    #[DataProvider('validIsins')]
    public function testItNormalizesAndValidatesAnIsin(string $submitted, string $canonical): void
    {
        self::assertSame($canonical, Isin::fromString($submitted)->toString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function validIsins(): iterable
    {
        yield 'French security' => ['FR0000120271', 'FR0000120271'];
        yield 'American security' => ['US0378331005', 'US0378331005'];
        yield 'Irish fund' => ['IE00B4L5Y983', 'IE00B4L5Y983'];
        yield 'demo security' => ['FRDEMO000015', 'FRDEMO000015'];
        yield 'trimmed lowercase input' => [' fr0000120271 ', 'FR0000120271'];
    }

    #[DataProvider('invalidIsins')]
    public function testItRejectsAnInvalidIsin(string $submitted): void
    {
        $this->expectException(InvalidInstrument::class);
        $this->expectExceptionMessage('ISIN');
        Isin::fromString($submitted);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIsins(): iterable
    {
        yield 'wrong check digit' => ['FR0000120272'];
        yield 'too short' => ['FR000012027'];
        yield 'non ASCII' => ['FR00001202ÉA'];
    }

    public function testItRequiresAtLeastOneIdentifier(): void
    {
        $this->expectException(InvalidInstrument::class);
        $this->expectExceptionMessage('identifier');
        $this->instrument(isin: null, ticker: null);
    }

    public function testItRejectsUnicodeWhitespaceOnlyAndTrimsUnicodeWhitespaceAtTheBoundary(): void
    {
        $trimmed = $this->instrument(name: "\u{00A0}ETF Monde\u{202F}");
        self::assertSame('ETF Monde', $trimmed->name);

        $this->expectException(InvalidInstrument::class);
        $this->expectExceptionMessage('name');
        $this->instrument(name: "\u{00A0}\u{2007}\u{202F}");
    }

    public function testItRejectsANameWhoseLowercaseFormExceedsTheStoredLength(): void
    {
        // "İ" lowercases to two code points, so 100 of them normalize to 200.
        try {
            $this->instrument(name: str_repeat("\u{0130}", 100));
            self::fail('An overlong normalized name must be refused.');
        } catch (InvalidInstrument $exception) {
            self::assertSame('invalid_instrument_name', $exception->problemCode);
        }
    }

    public function testReviewedMetadataKeepsExistingValuesAndFillsNullFields(): void
    {
        $instrument = $this->instrument(reviewedAt: new \DateTimeImmutable('2026-09-01T10:00:00Z'));

        $merge = $instrument->mergeExternalMetadata(new InstrumentMetadata(
            name: null,
            ticker: Ticker::fromString('DIFFERENT'),
            venueMic: null,
            instrumentType: null,
            assetClass: null,
            region: InstrumentRegion::WORLD,
        ), new \DateTimeImmutable('2026-09-02T10:00:00Z'));

        self::assertSame('DEMO', $merge->instrument->ticker?->toString());
        self::assertSame(InstrumentRegion::WORLD, $merge->instrument->region);
        self::assertCount(1, $merge->conflicts);
        self::assertSame('ticker', $merge->conflicts[0]->field);
        self::assertSame('DEMO', $merge->conflicts[0]->current);
        self::assertSame('DIFFERENT', $merge->conflicts[0]->proposed);
        self::assertSame('FR0000120271', $merge->instrument->isin?->toString());
        self::assertSame('EUR', $merge->instrument->currencyCode->toString());
    }

    public function testUnreviewedMetadataAppliesEveryNonNullField(): void
    {
        $instrument = $this->instrument(reviewedAt: null);

        $merge = $instrument->mergeExternalMetadata(new InstrumentMetadata(
            name: 'External name',
            ticker: Ticker::fromString('EXT'),
            venueMic: VenueMic::fromString('XNAS'),
            instrumentType: InstrumentType::ETF,
            assetClass: AssetClass::EQUITY,
            region: InstrumentRegion::NORTH_AMERICA,
        ), new \DateTimeImmutable('2026-09-02T10:00:00Z'));

        self::assertSame('External name', $merge->instrument->name);
        self::assertSame('EXT', $merge->instrument->ticker?->toString());
        self::assertSame(InstrumentType::ETF, $merge->instrument->instrumentType);
        self::assertSame([], $merge->conflicts);
        self::assertNull($merge->instrument->reviewedAt);
    }

    public function testAUsedInstrumentLocksIdentityButKeepsMetadataEditable(): void
    {
        $used = $this->instrument(usedAt: new \DateTimeImmutable('2026-09-01T10:00:00Z'));

        $renamed = $used->editManually(
            name: 'Renamed',
            isin: $used->isin,
            ticker: $used->ticker,
            venueMic: $used->venueMic,
            instrumentType: $used->instrumentType,
            assetClass: $used->assetClass,
            region: $used->region,
            currencyCode: $used->currencyCode,
            quantityPrecision: $used->quantityPrecision,
            pricePrecision: $used->pricePrecision,
            actorId: WorkspaceFixture::OWNER_ID,
            at: new \DateTimeImmutable('2026-09-02T10:00:00Z'),
        );
        self::assertSame('Renamed', $renamed->name);

        $this->expectException(InvalidInstrument::class);
        $this->expectExceptionMessage('locked');
        $used->editManually(
            name: $used->name,
            isin: $used->isin,
            ticker: $used->ticker,
            venueMic: $used->venueMic,
            instrumentType: $used->instrumentType,
            assetClass: $used->assetClass,
            region: $used->region,
            currencyCode: AssetCode::fromString('USD'),
            quantityPrecision: $used->quantityPrecision,
            pricePrecision: $used->pricePrecision,
            actorId: WorkspaceFixture::OWNER_ID,
            at: new \DateTimeImmutable('2026-09-02T10:00:00Z'),
        );
    }

    public function testAnEuroFundNeedsNoIdentifierAndCarriesNone(): void
    {
        $fund = $this->euroFund();

        self::assertSame(InstrumentType::EURO_FUND, $fund->instrumentType);
        self::assertNull($fund->isin);
        self::assertNull($fund->ticker);
        self::assertNull($fund->venueMic);
    }

    public function testAnEuroFundRefusesEveryIdentifier(): void
    {
        foreach ([
            ['isin' => Isin::fromString('FR0000120271'), 'ticker' => null, 'venue' => null],
            ['isin' => null, 'ticker' => Ticker::fromString('DEMO'), 'venue' => null],
            ['isin' => null, 'ticker' => null, 'venue' => VenueMic::fromString('XPAR')],
        ] as $identifiers) {
            try {
                $this->euroFund($identifiers['isin'], $identifiers['ticker'], $identifiers['venue']);
                self::fail('An identifier on a euro fund must be refused.');
            } catch (InvalidInstrument $exception) {
                self::assertSame('instrument_field_not_allowed', $exception->problemCode);
            }
        }
    }

    public function testAnEuroFundCannotBeEditedIntoAnotherTypeAfterItsFirstUse(): void
    {
        $this->expectException(InvalidInstrument::class);
        $this->euroFund(usedAt: new \DateTimeImmutable('2026-09-01T10:00:00Z'))->editManually(
            'Fonds', null, Ticker::fromString('NEW'), null, InstrumentType::FUND, null, null,
            AssetCode::fromString('EUR'), 2, 0, WorkspaceFixture::OWNER_ID, new \DateTimeImmutable('2026-09-02T10:00:00Z'),
        );
    }

    private function euroFund(?Isin $isin = null, ?Ticker $ticker = null, ?VenueMic $venue = null, ?\DateTimeImmutable $usedAt = null): Instrument
    {
        $now = new \DateTimeImmutable('2026-09-01T10:00:00Z');

        return new Instrument(
            '00000000-0000-7000-8000-000000000102', WorkspaceFixture::own(), 'Fonds euros Démo', $isin, $ticker, $venue,
            InstrumentType::EURO_FUND, null, null, AssetCode::fromString('EUR'), 2, 0, InstrumentSource::MANUAL,
            null, null, InstrumentStatus::ACTIVE, $usedAt, $now, WorkspaceFixture::OWNER_ID, $now, 1,
        );
    }

    private function instrument(
        ?string $isin = 'FR0000120271',
        ?string $ticker = 'DEMO',
        ?\DateTimeImmutable $reviewedAt = null,
        ?\DateTimeImmutable $usedAt = null,
        string $name = 'Demo instrument',
    ): Instrument {
        $now = new \DateTimeImmutable('2026-09-01T10:00:00Z');

        return new Instrument(
            id: '00000000-0000-7000-8000-000000000101',
            workspace: WorkspaceFixture::own(),
            name: $name,
            isin: null === $isin ? null : Isin::fromString($isin),
            ticker: null === $ticker ? null : Ticker::fromString($ticker),
            venueMic: VenueMic::fromString('XPAR'),
            instrumentType: InstrumentType::EQUITY,
            assetClass: AssetClass::EQUITY,
            region: null,
            currencyCode: AssetCode::fromString('EUR'),
            quantityPrecision: 6,
            pricePrecision: 4,
            source: InstrumentSource::MANUAL,
            reviewedAt: $reviewedAt,
            reviewedBy: null === $reviewedAt ? null : WorkspaceFixture::OWNER_ID,
            status: InstrumentStatus::ACTIVE,
            usedAt: $usedAt,
            createdAt: $now,
            createdBy: WorkspaceFixture::OWNER_ID,
            updatedAt: $now,
            version: 1,
        );
    }
}
