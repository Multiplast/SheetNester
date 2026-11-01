<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Options\Upsize;
use Multiplast\SheetNester\Result\Cut;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\Result\SheetPlan;
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

    /**
     * Solves a single-pass job on the given stock (default 6000 / 5000 / 4000 × 2100,
     * 5 each) with channels along X unless stated otherwise.
     *
     * @param array<int, array{int, int, int}>      $pieces
     * @param array<int, array{int, int, int|null}> $stock
     */
    private static function solveUpsize(
        Upsize   $upsize,
        Remnants $remnants,
        array    $pieces = [[3000, 2100, 1]],
        array    $stock = [[6000, 2100, 5], [5000, 2100, 5], [4000, 2100, 5]],
        string   $grain = Grain::ALONG_WIDTH,
        float    $kerf = 0.0
    ): NestingResult {
        $job = NestingJob::create(new Material($kerf, $grain), search: Search::singlePass(), remnants: $remnants, upsize: $upsize);
        foreach ($stock as [$w, $h, $q]) {
            $job->addStock($w, $h, $q);
        }
        foreach ($pieces as [$w, $h, $q]) {
            $job->addPiece($w, $h, $q);
        }
        return (new SheetNester())->solve($job);
    }

    /** @return int[] */
    private static function formats(NestingResult $result): array
    {
        return array_map(fn($s) => (int) $s->width, $result->sheets);
    }

    /** @return array<int, array{float, float, float, float}> */
    private static function remnantRects(SheetPlan $sheet): array
    {
        return array_map(fn($r) => [$r->x, $r->y, $r->width, $r->height], $sheet->remnants ?? []);
    }

    public function testUpsizeTradesABiggerSheetForAUsableRemnant(): void
    {
        // One 3000×2100 piece, formats 6000 / 5000 / 4000 × 2100. Least material
        // is the 4000 (its 1000×2100 leftover is below the threshold); the 6000
        // leaves a sellable 3000×2100 remnant – net material 6.3 m² instead of 8.4.
        $result = self::solveUpsize(Upsize::lastSheet(minWidth: 2500, minHeight: 1000), Remnants::atLeast(2500, 1000));
        $sheet  = $result->sheets[0];
        self::assertSame([6000], self::formats($result));
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $sheet->upsizedFrom);
        self::assertSame([[3000.0, 0.0, 3000.0, 2100.0]], self::remnantRects($sheet));
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
        $result = self::solveUpsize(Upsize::lastSheet(2000, 1000), Remnants::atLeast(2000, 1000));
        self::assertSame([5000], self::formats($result));
        self::assertSame([[3000.0, 0.0, 2000.0, 2100.0]], self::remnantRects($result->sheets[0]));
        self::assertSame(6.3, $result->netMaterialM2);

        // threshold 3500: no format yields a usable remnant → nothing changes
        $result = self::solveUpsize(Upsize::lastSheet(3500, 1000), Remnants::atLeast(3500, 1000));
        self::assertSame([4000], self::formats($result));
        self::assertNull($result->sheets[0]->upsizedFrom);
        self::assertSame([8.4, 0.0, 8.4], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);

        // a low upsize threshold accepts the 1000×2100 leftover as well: every
        // format ties on net material, so the smallest stays
        $result = self::solveUpsize(Upsize::lastSheet(200, 200), Remnants::auto(200));
        self::assertSame([4000], self::formats($result));
        self::assertSame([[3000.0, 0.0, 1000.0, 2100.0]], self::remnantRects($result->sheets[0]));

        // upsize off: marking only
        $result = self::solveUpsize(Upsize::none(), Remnants::atLeast(2500, 1000));
        self::assertSame([4000], self::formats($result));
        self::assertNull($result->sheets[0]->upsizedFrom);
        self::assertNull($result->toArray()['sheets'][0]['upsized_from']);

        // a full sheet never grows
        $result = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::atLeast(2500, 1000), [[3000, 2100, 2]]);
        self::assertSame([6000], self::formats($result));
        self::assertNull($result->sheets[0]->upsizedFrom);
        self::assertSame(12.6, $result->netMaterialM2);

        // registered remnant formats as the threshold
        $result = self::solveUpsize(Upsize::lastSheet(formats: [[2500, 2100]]), Remnants::defined([[2500, 2100]]));
        self::assertSame([6000], self::formats($result));
        self::assertSame([[3000.0, 0.0, 2500.0, 2100.0]], self::remnantRects($result->sheets[0]));
        self::assertSame(7.35, $result->netMaterialM2);

        // the last 6000 is taken by a full sheet; the 5000 does not pay (2000 wide leftover)
        $result = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::atLeast(2500, 1000), [[6000, 2100, 1], [3000, 2100, 1]], [[6000, 2100, 1], [5000, 2100, 5], [4000, 2100, 5]]);
        self::assertSame([6000, 4000], self::formats($result));
        self::assertNull($result->sheets[1]->upsizedFrom);

        // kerf: the footprint leaves 2996 mm, still above the threshold
        $result = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::atLeast(2500, 1000), [[3000, 2100, 1]], [[6000, 2100, 5], [4000, 2100, 5]], Grain::ALONG_WIDTH, 4.0);
        self::assertSame([6000], self::formats($result));
        self::assertSame([[3004.0, 0.0, 2996.0, 2100.0]], self::remnantRects($result->sheets[0]));
        self::assertSame([6.2916, 6.3084], [$result->remnantM2, $result->netMaterialM2]);

        // with Grain::NONE the minimum may be turned: 1000 × 2500 accepts a 3000 × 2100 remnant
        $result = self::solveUpsize(Upsize::lastSheet(1000, 2500), Remnants::atLeast(1000, 2500), [[3000, 2100, 1]], [[6000, 2100, 5], [4000, 2100, 5]], Grain::NONE);
        self::assertSame([6000], self::formats($result));
        self::assertSame(6.3, $result->netMaterialM2);
    }

    public function testLastSheetIsTheSheetOpenedLast(): void
    {
        // FFD order: the 3000×2100 piece opens sheet 1 (a 4000, 75 % used), the
        // two 3900×1000 pieces open sheet 2 (a 4000, 92.9 % used). The last sheet
        // is sheet 2 even though sheet 1 is the less used one: on a 6000 it gets
        // a 2100×2100 remnant (≥ 2000×1000), net 8.19 < 8.4 m².
        $result = self::solveUpsize(Upsize::lastSheet(2000, 1000), Remnants::atLeast(2000, 1000), [[3000, 2100, 1], [3900, 1000, 2]]);
        self::assertSame([6000, 4000], self::formats($result));
        self::assertSame([3900.0, 3900.0], array_map(fn($p) => $p->width, $result->sheets[0]->pieces));
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $result->sheets[0]->upsizedFrom);
        self::assertSame([[3900.0, 0.0, 2100.0, 2100.0]], self::remnantRects($result->sheets[0]));
        self::assertSame(3000.0, $result->sheets[1]->pieces[0]->width);
        self::assertNull($result->sheets[1]->upsizedFrom);

        // allSheets() enlarges the first sheet too (5000 and 6000 tie at 6.3 → 5000)
        $result = self::solveUpsize(Upsize::allSheets(2000, 1000), Remnants::atLeast(2000, 1000), [[3000, 2100, 1], [3900, 1000, 2]]);
        self::assertSame([6000, 5000], self::formats($result));
        self::assertSame(3000.0, $result->sheets[1]->pieces[0]->width);
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $result->sheets[1]->upsizedFrom);

        // lastSheet(): at most one sheet grows – two 3500 pieces on two 4000 →
        // only the last one becomes a 6000 with a 2500 remnant
        $result = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::atLeast(2500, 1000), [[3500, 2100, 2]]);
        self::assertSame([6000, 4000], self::formats($result));
        self::assertSame([[3500.0, 0.0, 2500.0, 2100.0]], self::remnantRects($result->sheets[0]));
        self::assertSame([], $result->sheets[1]->remnants);
        self::assertNull($result->sheets[1]->upsizedFrom);
        self::assertSame([21.0, 5.25, 15.75], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);
        $usage = [];
        foreach ($result->stockUsage as $u) {
            $usage[(int) $u->width] = [$u->used, $u->remaining];
        }
        self::assertSame([4000 => [1, 4], 6000 => [1, 4]], $usage);

        // allSheets(): both become a 6000 with a 2500 remnant
        $result = self::solveUpsize(Upsize::allSheets(2500, 1000), Remnants::atLeast(2500, 1000), [[3500, 2100, 2]]);
        self::assertSame([6000, 6000], self::formats($result));
        self::assertSame([25.2, 10.5, 14.7], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);
        self::assertSame([2, 3], [$result->stockUsage[0]->used, $result->stockUsage[0]->remaining]);
    }

    public function testUpsizeAndMarkingAreIndependent(): void
    {
        // no marking: the sheet still grows, the result just shows no remnants
        $result = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::none());
        self::assertSame([6000], self::formats($result));
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $result->sheets[0]->upsizedFrom);
        self::assertNull($result->sheets[0]->remnants);
        self::assertSame([null, null], [$result->remnantM2, $result->netMaterialM2]);

        // marking every leftover from 200 mm does not lower the upsize threshold:
        // the sheet grows for the 2500×1000 remnant (with one shared 200 mm
        // threshold every format would tie and the 4000 would stay)
        $result = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::auto(200));
        self::assertSame([6000], self::formats($result));
        self::assertSame([[3000.0, 0.0, 3000.0, 2100.0]], self::remnantRects($result->sheets[0]));

        // … and a high marking threshold does not raise it: nothing is marked
        $result = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::atLeast(4000, 1000));
        self::assertSame([6000], self::formats($result));
        self::assertSame([], $result->sheets[0]->remnants);
        self::assertSame(12.6, $result->netMaterialM2);
    }

    public function testDeprecatedRemnantsUpsizeArgumentStillWorks(): void
    {
        $job = NestingJob::create(remnants: Remnants::atLeast(2500, 1000, upsize: Remnants::UPSIZE_LAST));
        self::assertSame([Upsize::LAST_SHEET, 2500.0, 1000.0, []], [$job->upsize()->scope, $job->upsize()->minWidth, $job->upsize()->minHeight, $job->upsize()->formats]);

        $job = NestingJob::create(remnants: Remnants::defined([[2500, 2100]], upsize: Remnants::UPSIZE_ALL));
        self::assertSame([Upsize::ALL_SHEETS, [[2500.0, 2100.0]]], [$job->upsize()->scope, $job->upsize()->formats]);

        self::assertSame(Upsize::NONE, NestingJob::create(remnants: Remnants::auto(200))->upsize()->scope);

        $legacy = self::solveUpsize(Upsize::none(), Remnants::atLeast(2500, 1000, upsize: Remnants::UPSIZE_LAST));
        $new    = self::solveUpsize(Upsize::lastSheet(2500, 1000), Remnants::atLeast(2500, 1000));
        $legacy->calculationMs = $new->calculationMs = 0.0;
        self::assertSame($new->toArray(), $legacy->toArray());

        $this->expectException(\InvalidArgumentException::class);
        NestingJob::create(
            remnants: Remnants::atLeast(2500, 1000, upsize: Remnants::UPSIZE_LAST),
            upsize:   Upsize::lastSheet(2500, 1000),
        );
    }

    public function testUpsizeAppliesToSmallOrdersAsWell(): void
    {
        // A 1000×2100 piece: from the 2500 the 1500×2100 leftover is below the
        // threshold, from the 4000 it is a 3000×2100 remnant. The 5000 and the
        // 6000 tie with the 4000 on net material, so the smallest of them wins.
        $result = self::solveUpsize(
            Upsize::lastSheet(2500, 1000),
            Remnants::atLeast(2500, 1000),
            [[1000, 2100, 1]],
            [[6000, 2100, 5], [5000, 2100, 5], [4000, 2100, 5], [2500, 2100, 5]]
        );

        self::assertSame(4000.0, $result->sheets[0]->width);
        self::assertSame(['width' => 2500.0, 'height' => 2100.0], $result->sheets[0]->upsizedFrom);
        self::assertSame([8.4, 6.3, 2.1], [$result->materialM2, $result->remnantM2, $result->netMaterialM2]);
    }
}
