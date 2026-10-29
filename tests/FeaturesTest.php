<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Result\Cut;
use Multiplast\SheetNester\SheetNester;
use PHPUnit\Framework\TestCase;

/**
 * Small, hand-checkable jobs pinning down one documented behaviour each.
 */
final class FeaturesTest extends TestCase
{
    public function testSameSeedGivesIdenticalResults(): void
    {
        $a = GoldenDataset::normalize(GoldenDataset::solve(['search' => new Search(20, 42)])->toArray());
        $b = GoldenDataset::normalize(GoldenDataset::solve(['search' => new Search(20, 42)])->toArray());
        self::assertSame($a, $b, 'two runs with the same seed');

        $nester = new SheetNester();
        $job    = GoldenDataset::job(['search' => new Search(20, 42)]);
        self::assertSame(
            GoldenDataset::normalize($nester->solve($job)->toArray()),
            GoldenDataset::normalize($nester->solve($job)->toArray()),
            'solve() twice with one nester and one job'
        );
    }

    public function testTimeBudgetAlwaysRunsTheCanonicalPass(): void
    {
        $result = GoldenDataset::solve(['search' => new Search(restarts: 50, seed: 42, timeBudgetMs: 0.001)]);

        self::assertTrue($result->timeBudgetHit);
        self::assertSame(1, $result->passes);
        self::assertSame([], $result->unplaced);
        self::assertNotEmpty($result->sheets);
    }

    public function testSinglePassIgnoresSeedAndBudget(): void
    {
        $result = GoldenDataset::solve(['search' => Search::singlePass()]);

        self::assertSame(Objective::MIN_WASTE, $result->objective);
        self::assertSame(1, $result->passes);
        self::assertFalse($result->timeBudgetHit);
    }

    public function testSmallestFittingFormatIsChosen(): void
    {
        $job = NestingJob::create(search: Search::singlePass())
            ->addStock(2000, 2000, 5)
            ->addStock(1000, 1000, 5)
            ->addPiece(900, 900);

        $result = (new SheetNester())->solve($job);

        self::assertCount(1, $result->sheets);
        self::assertSame(1000.0, $result->sheets[0]->width);
        self::assertSame(1000.0, $result->sheets[0]->height);
        self::assertCount(1, $result->stockUsage);
        self::assertSame(1000.0, $result->stockUsage[0]->width);
        self::assertSame(1, $result->stockUsage[0]->used);
        self::assertSame(4, $result->stockUsage[0]->remaining);
    }

    public function testUnplacedPiecesCarryTheRightReason(): void
    {
        $job = NestingJob::create(new Material(0, Grain::ALONG_HEIGHT), search: Search::singlePass())
            ->addStock(1000, 1000, 1)
            ->addPiece(900, 900, 3)      // stock for one of them only
            ->addPiece(1500, 500, 1);    // too wide, and rotation is locked

        $result = (new SheetNester())->solve($job);
        self::assertCount(1, $result->sheets);

        $byDims = [];
        foreach ($result->unplaced as $u) {
            $byDims["{$u->width}x{$u->height}"] = $u;
        }
        self::assertCount(2, $byDims);
        self::assertSame(2, $byDims['900x900']->qty);
        self::assertSame('stock', $byDims['900x900']->reasonCode);
        self::assertSame(1, $byDims['1500x500']->qty);
        self::assertSame('too_large', $byDims['1500x500']->reasonCode);
        self::assertSame(3, $result->unplacedCount());
    }

    public function testRotationOnlyWithoutGrain(): void
    {
        $locked = NestingJob::create(new Material(0, Grain::ALONG_HEIGHT), search: Search::singlePass())
            ->addStock(600, 2000, 1)
            ->addPiece(1500, 500);
        $result = (new SheetNester())->solve($locked);
        self::assertCount(0, $result->sheets);
        self::assertCount(1, $result->unplaced);

        $free = NestingJob::create(new Material(0, Grain::NONE), search: Search::singlePass())
            ->addStock(600, 2000, 1)
            ->addPiece(1500, 500);
        $result = (new SheetNester())->solve($free);
        self::assertCount(1, $result->sheets);
        $piece = $result->sheets[0]->pieces[0];
        self::assertTrue($piece->rotated);
        self::assertSame(500.0, $piece->width);
        self::assertSame(1500.0, $piece->height);
    }

    public function testSheetsAreOrderedLargestFormatFirst(): void
    {
        $job = NestingJob::create(search: Search::singlePass())
            ->addStock(1000, 1000, 1)
            ->addStock(3000, 2000, 1)
            ->addStock(2000, 2000, 1)
            ->addPiece(900, 900)
            ->addPiece(2900, 1900)
            ->addPiece(1900, 1900);

        $result = (new SheetNester())->solve($job);

        $formats = array_map(fn($s) => "{$s->width}x{$s->height}", $result->sheets);
        self::assertSame(['3000x2000', '2000x2000', '1000x1000'], $formats);
        self::assertSame([0, 1, 2], array_map(fn($s) => $s->index, $result->sheets));
    }

    public function testAutoRemnantsMarkTheLargestFreeRectangle(): void
    {
        $job = NestingJob::create(search: Search::singlePass(), remnants: Remnants::auto(200))
            ->addStock(2000, 1000, 1)
            ->addPiece(1000, 1000);

        $result = (new SheetNester())->solve($job);
        $sheet  = $result->sheets[0];

        self::assertCount(1, $sheet->remnants);
        self::assertSame([1000.0, 0.0, 1000.0, 1000.0], [$sheet->remnants[0]->x, $sheet->remnants[0]->y, $sheet->remnants[0]->width, $sheet->remnants[0]->height]);
        self::assertSame(1.0, $sheet->remnantM2);
        self::assertSame(1.0, $result->remnantM2);
        self::assertSame(50.0, $result->remnantPct);
    }

    public function testAutoRemnantsAreNotHiddenByAThinLargestRectangle(): void
    {
        // Free space: a 6000×150 strip below the piece (the largest empty
        // rectangle, too thin) and a 500×1000 block to its right (usable).
        $job = NestingJob::create(search: Search::singlePass(), remnants: Remnants::auto(200))
            ->addStock(6000, 1000, 1)
            ->addPiece(5500, 850);

        $sheet = (new SheetNester())->solve($job)->sheets[0];

        self::assertCount(1, $sheet->remnants);
        self::assertSame([5500.0, 0.0, 500.0, 1000.0], [$sheet->remnants[0]->x, $sheet->remnants[0]->y, $sheet->remnants[0]->width, $sheet->remnants[0]->height]);
        self::assertSame(0.5, $sheet->remnantM2);
    }

    public function testMinimumRemnantSizeFollowsTheOrientationRules(): void
    {
        // Free space: a 6000×300 strip (largest) and a 500×1000 block at x = 5500.
        $solve = static function (string $grain, Remnants $remnants) {
            $job = NestingJob::create(new Material(0, $grain), search: Search::singlePass(), remnants: $remnants)
                ->addStock(6000, 1000, 1)
                ->addPiece(5500, 700);
            return (new SheetNester())->solve($job)->sheets[0];
        };
        $dims = fn($sheet) => array_map(fn($r) => [$r->x, $r->y, $r->width, $r->height], $sheet->remnants);

        // 400 × 400: the strip is too low, the block qualifies
        $sheet = $solve(Grain::ALONG_HEIGHT, Remnants::atLeast(400, 400));
        self::assertSame([[5500.0, 0.0, 500.0, 1000.0]], $dims($sheet));
        self::assertSame(0.5, $sheet->remnantM2);

        // 1000 wide × 400 high: with channels the 500-wide block does not qualify …
        $sheet = $solve(Grain::ALONG_HEIGHT, Remnants::atLeast(width: 1000, height: 400));
        self::assertSame([], $sheet->remnants);
        self::assertSame(0.0, $sheet->remnantM2);

        // … without channels it may be turned, so it does
        $sheet = $solve(Grain::NONE, Remnants::atLeast(width: 1000, height: 400));
        self::assertSame([[5500.0, 0.0, 500.0, 1000.0]], $dims($sheet));

        // the square minimum accepts the strip first (largest), then what is
        // left of the block above it
        $sheet = $solve(Grain::ALONG_HEIGHT, Remnants::auto(300));
        self::assertSame([[0.0, 700.0, 6000.0, 300.0], [5500.0, 0.0, 500.0, 700.0]], $dims($sheet));
        self::assertSame(2.15, $sheet->remnantM2);
    }

    public function testDefinedRemnantsFillTheFreeArea(): void
    {
        $job = NestingJob::create(search: Search::singlePass(), remnants: Remnants::defined([[2000, 2000], [500, 500]]))
            ->addStock(2000, 1000, 1)
            ->addPiece(1000, 1000);

        $result = (new SheetNester())->solve($job);

        $remnants = $result->sheets[0]->remnants;
        self::assertCount(4, $remnants);                 // 2000×2000 never fits, 500×500 fits 4×
        foreach ($remnants as $r) {
            self::assertSame(500.0, $r->width);
            self::assertSame(500.0, $r->height);
            self::assertGreaterThanOrEqual(1000.0, $r->x);
        }
        self::assertSame(1.0, $result->remnantM2);
    }

    public function testRemnantsAreNullWhenDisabled(): void
    {
        $job = NestingJob::create(search: Search::singlePass())
            ->addStock(2000, 1000, 1)
            ->addPiece(1000, 1000);

        $result = (new SheetNester())->solve($job);

        self::assertNull($result->sheets[0]->remnants);
        self::assertNull($result->sheets[0]->remnantM2);
        self::assertNull($result->remnantM2);
        self::assertNull($result->remnantPct);
        self::assertArrayNotHasKey('remnants', $result->toLegacyArray()['sheets'][0]);
    }

    public function testCutsOfTwoPiecesSideBySide(): void
    {
        $job = NestingJob::create(search: Search::singlePass())
            ->addStock(2000, 500, 1)
            ->addPiece(1000, 500, 2);

        $result = (new SheetNester())->solve($job);
        $cuts   = $result->sheets[0]->cuts;

        self::assertSame([], $cuts->horizontal);
        self::assertCount(1, $cuts->vertical);
        $cut = $cuts->vertical[0];
        self::assertSame([Cut::VERTICAL, 1000.0, 0.0, 500.0, 500.0], [$cut->axis, $cut->position, $cut->start, $cut->end, $cut->length]);
        self::assertSame(500.0, $cuts->totalMm);
        self::assertSame(1, $cuts->count());
        self::assertSame(1, $result->cutCount);
        self::assertSame(500.0, $result->cutsMm);
    }

    public function testKerfDoesNotDoubleCountCuts(): void
    {
        // Two pieces side by side with a 4 mm kerf: one blade pass between them.
        $job = NestingJob::create(new Material(kerf: 4), search: Search::singlePass())
            ->addStock(2004, 500, 1)
            ->addPiece(1000, 500, 2);

        $result = (new SheetNester())->solve($job);
        $sheet  = $result->sheets[0];

        self::assertSame([0.0, 1004.0], [$sheet->pieces[0]->x, $sheet->pieces[1]->x]);
        self::assertCount(1, $sheet->cuts->vertical);
        self::assertSame(1000.0, $sheet->cuts->vertical[0]->position);   // right edge of the first piece
        self::assertSame(500.0, $sheet->cuts->vertical[0]->length);
        self::assertSame(1, $result->cutCount);
        self::assertSame(500.0, $result->cutsMm);

        // Stacked with kerf: one horizontal pass, and the shared left edge is
        // reported once even though the second piece sits in a lower free rect.
        $stacked = (new SheetNester())->solve(
            NestingJob::create(new Material(kerf: 4), search: Search::singlePass())
                ->addStock(1500, 1004, 1)
                ->addPiece(1000, 500, 2)
        );
        $cuts = $stacked->sheets[0]->cuts;
        self::assertCount(1, $cuts->horizontal);
        self::assertSame(500.0, $cuts->horizontal[0]->position);
        self::assertCount(1, $cuts->vertical);
        self::assertSame(1000.0, $cuts->vertical[0]->position);
        self::assertSame([0.0, 1004.0], [$cuts->vertical[0]->start, $cuts->vertical[0]->end]);
    }

    public function testUpsizeTradesABiggerSheetForAUsableRemnant(): void
    {
        // One 3000×2100 piece, formats 6000 / 5000 / 4000 × 2100. Least material
        // is the 4000 (its 1000×2100 leftover is below the threshold); the 6000
        // leaves a sellable 3000×2100 remnant – net material 6.3 m² instead of 8.4.
        $solve = static function (
            Remnants $remnants,
            array    $pieces = [[3000, 2100, 1]],
            array    $stock = [[6000, 2100, 5], [5000, 2100, 5], [4000, 2100, 5]],
            string   $grain = Grain::ALONG_WIDTH,
            float    $kerf = 0.0
        ) {
            $job = NestingJob::create(new Material($kerf, $grain), search: Search::singlePass(), remnants: $remnants);
            foreach ($stock as [$w, $h, $q]) {
                $job->addStock($w, $h, $q);
            }
            foreach ($pieces as [$w, $h, $q]) {
                $job->addPiece($w, $h, $q);
            }
            return (new SheetNester())->solve($job);
        };
        $formats  = fn($r) => array_map(fn($s) => (int) $s->width, $r->sheets);
        $remnants = fn($s) => array_map(fn($r) => [$r->x, $r->y, $r->width, $r->height], $s->remnants);

        $result = $solve(Remnants::atLeast(2500, 1000, upsize: true));
        $sheet  = $result->sheets[0];
        self::assertSame([6000], $formats($result));
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $sheet->upsizedFrom);
        self::assertSame([[3000.0, 0.0, 3000.0, 2100.0]], $remnants($sheet));
        self::assertSame([12.6, 6.3, 6.3], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);
        self::assertSame(50.0, $sheet->usedPct);
        self::assertSame(1, $result->cutCount);                              // the piece's right edge became a cut
        self::assertSame(3000.0, $sheet->cuts->vertical[0]->position);
        self::assertCount(1, $result->stockUsage);
        self::assertSame([6000.0, 1, 4], [$result->stockUsage[0]->width, $result->stockUsage[0]->used, $result->stockUsage[0]->remaining]);
        $array = $result->toArray();
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $array['sheets'][0]['upsized_from']);
        self::assertSame(6.3, $array['totals']['net_material_m2']);
        self::assertArrayNotHasKey('upsized_from', $result->toLegacyArray()['sheets'][0]);

        // threshold 2000: the 5000 and the 6000 tie on net material → the smaller one
        $result = $solve(Remnants::atLeast(2000, 1000, upsize: true));
        self::assertSame([5000], $formats($result));
        self::assertSame([[3000.0, 0.0, 2000.0, 2100.0]], $remnants($result->sheets[0]));
        self::assertSame(6.3, $result->netMaterialM2);

        // threshold 3500: no format yields a usable remnant → nothing changes
        $result = $solve(Remnants::atLeast(3500, 1000, upsize: true));
        self::assertSame([4000], $formats($result));
        self::assertNull($result->sheets[0]->upsizedFrom);
        self::assertSame([8.4, 0.0, 8.4], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);

        // a low threshold accepts the 1000×2100 leftover as well: every format
        // ties on net material, so the smallest stays
        $result = $solve(Remnants::auto(200, upsize: true));
        self::assertSame([4000], $formats($result));
        self::assertSame([[3000.0, 0.0, 1000.0, 2100.0]], $remnants($result->sheets[0]));

        // upsize off: marking only
        $result = $solve(Remnants::atLeast(2500, 1000));
        self::assertSame([4000], $formats($result));
        self::assertNull($result->sheets[0]->upsizedFrom);
        self::assertNull($result->toArray()['sheets'][0]['upsized_from']);

        // a full sheet never grows
        $result = $solve(Remnants::atLeast(2500, 1000, upsize: true), [[3000, 2100, 2]]);
        self::assertSame([6000], $formats($result));
        self::assertNull($result->sheets[0]->upsizedFrom);
        self::assertSame(12.6, $result->netMaterialM2);

        // at most one sheet grows: two 3500 pieces on two 4000 → only one of them
        // becomes a 6000 with a 2500 remnant, the other keeps its 500 mm waste
        $result = $solve(Remnants::atLeast(2500, 1000, upsize: true), [[3500, 2100, 2]]);
        self::assertSame([6000, 4000], $formats($result));
        self::assertSame([[3500.0, 0.0, 2500.0, 2100.0]], $remnants($result->sheets[0]));
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $result->sheets[0]->upsizedFrom);
        self::assertSame([], $result->sheets[1]->remnants);
        self::assertNull($result->sheets[1]->upsizedFrom);
        self::assertSame([21.0, 5.25, 15.75], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);
        $usage = [];
        foreach ($result->stockUsage as $u) {
            $usage[(int) $u->width] = [$u->used, $u->remaining];
        }
        self::assertSame([4000 => [1, 4], 6000 => [1, 4]], $usage);

        // the leftover sheet is the one with the lowest utilisation: a 3500 and a
        // 3000 piece each on a 4000 → the 3000 (75 %) grows, the 3500 (87.5 %) stays
        $result = $solve(Remnants::atLeast(2500, 1000, upsize: true), [[3500, 2100, 1], [3000, 2100, 1]]);
        self::assertSame([6000, 4000], $formats($result));
        self::assertSame(3000.0, $result->sheets[0]->pieces[0]->width);
        self::assertSame([[3000.0, 0.0, 3000.0, 2100.0]], $remnants($result->sheets[0]));
        self::assertSame(3500.0, $result->sheets[1]->pieces[0]->width);
        self::assertNull($result->sheets[1]->upsizedFrom);

        // registered remnant formats as the threshold
        $result = $solve(Remnants::defined([[2500, 2100]], upsize: true));
        self::assertSame([6000], $formats($result));
        self::assertSame([[3000.0, 0.0, 2500.0, 2100.0]], $remnants($result->sheets[0]));
        self::assertSame(7.35, $result->netMaterialM2);

        // the last 6000 is taken by a full sheet; the 5000 does not pay (2000 wide leftover)
        $result = $solve(Remnants::atLeast(2500, 1000, upsize: true), [[6000, 2100, 1], [3000, 2100, 1]], [[6000, 2100, 1], [5000, 2100, 5], [4000, 2100, 5]]);
        self::assertSame([6000, 4000], $formats($result));
        self::assertNull($result->sheets[1]->upsizedFrom);

        // kerf: the footprint leaves 2996 mm, still above the threshold
        $result = $solve(Remnants::atLeast(2500, 1000, upsize: true), [[3000, 2100, 1]], [[6000, 2100, 5], [4000, 2100, 5]], Grain::ALONG_WIDTH, 4.0);
        self::assertSame([6000], $formats($result));
        self::assertSame([[3004.0, 0.0, 2996.0, 2100.0]], $remnants($result->sheets[0]));
        self::assertSame([6.2916, 6.3084], [$result->remnantM2, $result->netMaterialM2]);

        // with Grain::NONE the minimum may be turned: 1000 × 2500 accepts a 3000 × 2100 remnant
        $result = $solve(Remnants::atLeast(1000, 2500, upsize: true), [[3000, 2100, 1]], [[6000, 2100, 5], [4000, 2100, 5]], Grain::NONE);
        self::assertSame([6000], $formats($result));
        self::assertSame(6.3, $result->netMaterialM2);
    }

    public function testUpsizeAppliesToSmallOrdersAsWell(): void
    {
        // A 1000×2100 piece: from the 2500 the 1500×2100 leftover is below the
        // threshold, from the 4000 it is a 3000×2100 remnant. The 5000 and the
        // 6000 tie with the 4000 on net material, so the smallest of them wins.
        $job = NestingJob::create(new Material(0, Grain::ALONG_WIDTH), search: Search::singlePass(), remnants: Remnants::atLeast(2500, 1000, upsize: true))
            ->addStock(6000, 2100, 5)
            ->addStock(5000, 2100, 5)
            ->addStock(4000, 2100, 5)
            ->addStock(2500, 2100, 5)
            ->addPiece(1000, 2100);

        $result = (new SheetNester())->solve($job);

        self::assertSame(4000.0, $result->sheets[0]->width);
        self::assertSame(['width' => 2500.0, 'height' => 2100.0], $result->sheets[0]->upsizedFrom);
        self::assertSame([8.4, 6.3, 2.1], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);
    }
}
