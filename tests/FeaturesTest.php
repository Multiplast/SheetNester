<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\SheetNester;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;

/**
 * Small, hand-checkable jobs pinning down one documented behaviour each.
 */
final class FeaturesTest extends TestCase
{
    public function testSameSeedGivesIdenticalResults(): void
    {
        $a = GoldenDataset::normalize(GoldenDataset::nester(['restarts' => 20])->calculate());
        $b = GoldenDataset::normalize(GoldenDataset::nester(['restarts' => 20])->calculate());
        self::assertSame($a, $b, 'two instances with the same seed');

        $nester = GoldenDataset::nester(['restarts' => 20]);
        self::assertSame(
            GoldenDataset::normalize($nester->calculate()),
            GoldenDataset::normalize($nester->calculate()),
            'calculate() twice on one instance'
        );
    }

    public function testTimeBudgetAlwaysRunsTheCanonicalPass(): void
    {
        $result = GoldenDataset::nester(['restarts' => 50, 'timeBudgetMs' => 0.001])->calculate();

        self::assertTrue($result['time_budget_hit']);
        self::assertSame(1, $result['permutations_tested']);
        self::assertSame([], $result['unplaced_pieces']);
        self::assertGreaterThan(0, $result['total_sheets']);
    }

    public function testSmallestFitIsASinglePass(): void
    {
        $result = GoldenDataset::nester([
            'formatStrategy' => SheetNester::STRATEGY_SMALLEST_FIT,
            'restarts'       => 500,
        ])->calculate();

        self::assertSame(SheetNester::STRATEGY_SMALLEST_FIT, $result['strategy']);
        self::assertSame(1, $result['permutations_tested']);
        self::assertFalse($result['time_budget_hit']);
    }

    public function testSmallestFittingFormatIsChosen(): void
    {
        $nester = new SheetNester(kerf: 0);
        $nester->addStockFormat(2000, 2000, 5);
        $nester->addStockFormat(1000, 1000, 5);
        $nester->addPiece(900, 900, 1);

        $result = $nester->calculate();

        self::assertSame(1, $result['total_sheets']);
        self::assertSame(1000.0, $result['sheets'][0]['stock_width']);
        self::assertSame(1000.0, $result['sheets'][0]['stock_height']);
        self::assertSame(
            [['width' => 1000.0, 'height' => 1000.0, 'used' => 1, 'remaining' => 4]],
            $result['stock_usage']
        );
    }

    public function testUnplacedPiecesCarryTheRightReason(): void
    {
        $nester = new SheetNester(kerf: 0, grainDirection: 'vertical');
        $nester->addStockFormat(1000, 1000, 1);
        $nester->addPiece(900, 900, 3);     // stock for one of them only
        $nester->addPiece(1500, 500, 1);    // too wide, and rotation is locked

        $result = $nester->calculate();
        self::assertSame(1, $result['total_sheets']);

        $byDims = [];
        foreach ($result['unplaced_pieces'] as $u) {
            $byDims["{$u['width']}x{$u['height']}"] = $u;
        }
        self::assertCount(2, $byDims);
        self::assertSame(2, $byDims['900x900']['qty']);
        self::assertSame('Nedostatek skladových zásob.', $byDims['900x900']['reason']);
        self::assertSame(1, $byDims['1500x500']['qty']);
        self::assertSame('Kus je větší než všechny dostupné formáty desek.', $byDims['1500x500']['reason']);
    }

    public function testRotationOnlyWhenGrainIsNone(): void
    {
        $locked = new SheetNester(kerf: 0, grainDirection: 'vertical');
        $locked->addStockFormat(600, 2000, 1);
        $locked->addPiece(1500, 500, 1);
        $result = $locked->calculate();
        self::assertSame(0, $result['total_sheets']);
        self::assertCount(1, $result['unplaced_pieces']);

        $free = new SheetNester(kerf: 0, grainDirection: 'none');
        $free->addStockFormat(600, 2000, 1);
        $free->addPiece(1500, 500, 1);
        $result = $free->calculate();
        self::assertSame(1, $result['total_sheets']);
        $piece = $result['sheets'][0]['pieces'][0];
        self::assertTrue($piece['rotated']);
        self::assertSame(500.0, $piece['width']);
        self::assertSame(1500.0, $piece['height']);
    }

    public function testSheetsAreOrderedLargestFormatFirst(): void
    {
        $nester = new SheetNester(kerf: 0);
        $nester->addStockFormat(1000, 1000, 1);
        $nester->addStockFormat(3000, 2000, 1);
        $nester->addStockFormat(2000, 2000, 1);
        $nester->addPiece(900, 900, 1);
        $nester->addPiece(2900, 1900, 1);
        $nester->addPiece(1900, 1900, 1);

        $result = $nester->calculate();

        $formats = array_map(
            static fn(array $s): string => "{$s['stock_width']}x{$s['stock_height']}",
            $result['sheets']
        );
        self::assertSame(['3000x2000', '2000x2000', '1000x1000'], $formats);
        self::assertSame([0, 1, 2], array_column($result['sheets'], 'sheet_index'));
    }

    public function testAutoRemnantsMarkTheLargestFreeRectangle(): void
    {
        $nester = new SheetNester(kerf: 0, remnantMode: SheetNester::REMNANT_AUTO, minRemnantSide: 200);
        $nester->addStockFormat(2000, 1000, 1);
        $nester->addPiece(1000, 1000, 1);

        $result = $nester->calculate();

        self::assertSame(
            [['x' => 1000.0, 'y' => 0.0, 'width' => 1000.0, 'height' => 1000.0]],
            $result['sheets'][0]['remnants']
        );
        self::assertSame(1.0, $result['sheets'][0]['remnant_area_m2']);
        self::assertSame(1.0, $result['total_remnant_m2']);
        self::assertSame(50.0, $result['total_remnant_pct']);
    }

    public function testDefinedRemnantsFillTheFreeArea(): void
    {
        $nester = new SheetNester(kerf: 0, remnantMode: SheetNester::REMNANT_DEFINED);
        $nester->addStockFormat(2000, 1000, 1);
        $nester->addPiece(1000, 1000, 1);
        $nester->addRemnantFormat(2000, 2000);   // never fits
        $nester->addRemnantFormat(500, 500);     // fits 4× into the free 1000×1000

        $result = $nester->calculate();

        $remnants = $result['sheets'][0]['remnants'];
        self::assertCount(4, $remnants);
        foreach ($remnants as $r) {
            self::assertSame(500.0, $r['width']);
            self::assertSame(500.0, $r['height']);
            self::assertGreaterThanOrEqual(1000.0, $r['x']);
        }
        self::assertSame(1.0, $result['total_remnant_m2']);
    }

    public function testRemnantKeysAreAbsentWhenDisabled(): void
    {
        $nester = new SheetNester(kerf: 0);
        $nester->addStockFormat(2000, 1000, 1);
        $nester->addPiece(1000, 1000, 1);

        $result = $nester->calculate();

        self::assertArrayNotHasKey('remnants', $result['sheets'][0]);
        self::assertArrayNotHasKey('total_remnant_m2', $result);
    }

    public function testCutsOfTwoPiecesSideBySide(): void
    {
        $nester = new SheetNester(kerf: 0);
        $nester->addStockFormat(2000, 500, 1);
        $nester->addPiece(1000, 500, 2);

        $result = $nester->calculate();
        $cuts   = $result['sheets'][0]['cuts'];

        self::assertSame([], $cuts['cuts_horizontal']);
        self::assertSame(
            [['x' => 1000.0, 'y_start' => 0.0, 'y_end' => 500.0, 'length' => 500.0]],
            $cuts['cuts_vertical']
        );
        self::assertSame(500.0, $cuts['total_mm']);
        self::assertSame(1, $result['total_cuts_count']);
        self::assertSame(500.0, $result['total_cuts_mm']);
    }

    public function testSvgPreview(): void
    {
        $nester = new SheetNester(kerf: 0, remnantMode: SheetNester::REMNANT_AUTO);
        $nester->addStockFormat(2000, 1000, 1);
        $nester->addPiece(1000, 1000, 1);
        $nester->calculate();

        $svg = $nester->toSvg(0);
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringEndsWith('</svg>', $svg);
        self::assertStringContainsString('1000×1000', $svg);
        self::assertStringContainsString('zbytek 1000×1000', $svg);

        $this->expectException(OutOfRangeException::class);
        $nester->toSvg(1);
    }

    public function testResultContainsTheDocumentedKeys(): void
    {
        $result = GoldenDataset::nester(['formatStrategy' => SheetNester::STRATEGY_SMALLEST_FIT])->calculate();

        foreach ([
            'sheets', 'stock_usage', 'unplaced_pieces', 'total_sheets', 'total_waste_pct',
            'utilization_pct', 'total_material_m2', 'total_cuts_count', 'total_cuts_mm',
            'total_cuts_horiz_mm', 'total_cuts_vert_mm', 'total_seal_mm', 'total_remnant_m2',
            'total_remnant_pct', 'strategy', 'permutations_tested', 'time_budget_hit',
            'calculation_time_ms',
        ] as $key) {
            self::assertArrayHasKey($key, $result);
        }
        foreach (['sheet_index', 'stock_width', 'stock_height', 'pieces', 'used_area_pct', 'cuts', 'remnants', 'remnant_area_m2'] as $key) {
            self::assertArrayHasKey($key, $result['sheets'][0]);
        }
        foreach (['width', 'height', 'x', 'y', 'rotated'] as $key) {
            self::assertArrayHasKey($key, $result['sheets'][0]['pieces'][0]);
        }
    }
}
