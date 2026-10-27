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
}
