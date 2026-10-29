<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use InvalidArgumentException;
use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\SheetNester;
use PHPUnit\Framework\TestCase;

/**
 * The job / options API: defaults, validation, labels, unlimited stock, seal
 * semantics and the array shape of the result.
 */
final class JobApiTest extends TestCase
{
    public function testJobDefaults(): void
    {
        $job = NestingJob::create();

        self::assertSame(0.0, $job->material()->kerf);
        self::assertSame(Grain::NONE, $job->material()->grain);
        self::assertTrue($job->material()->allowsRotation());
        self::assertSame(Objective::MIN_WASTE, $job->objective()->name);
        self::assertSame(20, $job->search()->restarts);
        self::assertTrue($job->search()->portfolio);
        self::assertFalse($job->search()->isSinglePass());
        self::assertFalse($job->remnants()->enabled());
        self::assertFalse($job->remnants()->upsize);
        self::assertSame([], $job->stock());
        self::assertSame([], $job->pieces());
    }

    public function testOptionsValidateTheirArguments(): void
    {
        $invalid = [
            'negative kerf'      => fn() => new Material(kerf: -1),
            'unknown grain'      => fn() => new Material(grain: 'vertical'),
            'negative cut cost'  => fn() => Objective::minCuts(-0.1),
            'negative restarts'  => fn() => new Search(restarts: -1),
            'zero time budget'   => fn() => new Search(timeBudgetMs: 0),
            'zero remnant side'  => fn() => Remnants::auto(0),
            'zero remnant width' => fn() => Remnants::atLeast(0, 100),
            'zero remnant height'=> fn() => Remnants::atLeast(100, 0),
            'no remnant formats' => fn() => Remnants::defined([]),
            'bad remnant format' => fn() => Remnants::defined([[1000, 0]]),
            'stock width 0'      => fn() => NestingJob::create()->addStock(0, 1000),
            'stock quantity 0'   => fn() => NestingJob::create()->addStock(1000, 1000, 0),
            'piece width 0'      => fn() => NestingJob::create()->addPiece(0, 100),
            'piece quantity 0'   => fn() => NestingJob::create()->addPiece(100, 100, 0),
        ];
        foreach ($invalid as $label => $call) {
            try {
                $call();
                self::fail("Accepted invalid {$label}");
            } catch (InvalidArgumentException $e) {
                self::addToAssertionCount(1);
            }
        }

        Grain::assertValid(Grain::ALONG_WIDTH);
        self::assertFalse(Grain::allowsRotation(Grain::ALONG_WIDTH));
    }

    public function testAutoRemnantsAreTheSquareCaseOfAtLeast(): void
    {
        $auto = Remnants::auto(200);
        self::assertSame([Remnants::AUTO, 200.0, 200.0, 200.0], [$auto->mode, $auto->minWidth, $auto->minHeight, $auto->minSide]);

        $atLeast = Remnants::atLeast(500, 1000);
        self::assertSame([Remnants::AUTO, 500.0, 1000.0, 500.0], [$atLeast->mode, $atLeast->minWidth, $atLeast->minHeight, $atLeast->minSide]);
        self::assertTrue($atLeast->enabled());
        self::assertFalse($atLeast->upsize);

        self::assertSame([0.0, 0.0], [Remnants::none()->minWidth, Remnants::defined([[100, 100]])->minHeight]);

        self::assertTrue(Remnants::atLeast(500, 1000, upsize: true)->upsize);
        self::assertTrue(Remnants::auto(200, upsize: true)->upsize);
        self::assertTrue(Remnants::defined([[100, 100]], upsize: true)->upsize);
        self::assertFalse(Remnants::none()->upsize);
    }

    public function testSealFollowsTheGrainAxis(): void
    {
        $solve = static function (string $grain): NestingResult {
            $job = NestingJob::create(new Material(kerf: 0, grain: $grain), search: Search::singlePass())
                ->addStock(2000, 1000, 1)
                ->addPiece(1000, 500, 2);   // two pieces side by side
            return (new SheetNester())->solve($job);
        };

        // channels along X → open ends are the left and right edges → 2 × height per piece
        $alongWidth = $solve(Grain::ALONG_WIDTH);
        self::assertSame(2000.0, $alongWidth->sealMm);
        self::assertSame(2000.0, $alongWidth->sheets[0]->sealMm);
        self::assertSame(2000.0, $alongWidth->toLegacyArray()['total_seal_mm']);

        // channels along Y → open ends are the top and bottom edges → 2 × width per piece
        $alongHeight = $solve(Grain::ALONG_HEIGHT);
        self::assertSame(4000.0, $alongHeight->sealMm);
        self::assertSame(4000.0, $alongHeight->sheets[0]->sealMm);
        self::assertSame(4000.0, $alongHeight->toLegacyArray()['sheets'][0]['cuts']['seal_mm']);

        // no channels → nothing to seal
        $none = $solve(Grain::NONE);
        self::assertNull($none->sealMm);
        self::assertNull($none->sheets[0]->sealMm);
        self::assertSame(0.0, $none->toLegacyArray()['total_seal_mm']);
    }

    public function testLabelsTravelToPlacementsAndUnplacedPieces(): void
    {
        $job = NestingJob::create(new Material(0, Grain::ALONG_HEIGHT), search: Search::singlePass())
            ->addStock(1000, 1000, 1)
            ->addPiece(900, 400, 1, 'A')     // placed
            ->addPiece(900, 400, 1, 'C')     // same size, different label – placed
            ->addPiece(1500, 100, 1, 'B')    // too large (rotation locked)
            ->addPiece(900, 400, 1)          // no stock left → unlabeled
            ->addPiece(900, 400, 1);         // no stock left → merged with the previous one

        $result = (new SheetNester())->solve($job);

        $labels = array_map(fn($p) => $p->label, $result->sheets[0]->pieces);
        sort($labels);
        self::assertSame(['A', 'C'], $labels);

        $unplaced = [];
        foreach ($result->unplaced as $u) {
            $unplaced[(string) $u->label] = [$u->qty, $u->reasonCode];
        }
        self::assertSame([2, 'stock'], $unplaced['']);          // two unlabeled 900×400 merged
        self::assertSame([1, 'too_large'], $unplaced['B']);
        self::assertSame('Larger than every stock format.', $result->unplaced[1]->reason());

        $legacy = $result->toLegacyArray();
        self::assertArrayNotHasKey('label', $legacy['sheets'][0]['pieces'][0]);
        self::assertSame('Nedostatek skladových zásob.', $legacy['unplaced_pieces'][0]['reason']);
        self::assertSame('B', $result->toArray()['unplaced'][1]['label']);
    }

    public function testUnlimitedStock(): void
    {
        $job = NestingJob::create(search: Search::singlePass())
            ->addStock(1000, 1000)              // quantity omitted = unlimited
            ->addPiece(900, 900, 25);

        $result = (new SheetNester())->solve($job);

        self::assertCount(25, $result->sheets);
        self::assertSame([], $result->unplaced);
        self::assertSame(25, $result->stockUsage[0]->used);
        self::assertNull($result->stockUsage[0]->remaining);
        self::assertNull($result->toArray()['stock_usage'][0]['remaining']);
    }

    public function testToArrayShape(): void
    {
        $result = GoldenDataset::solve(['search' => Search::singlePass()]);
        $array  = $result->toArray();

        self::assertSame(['objective', 'passes', 'time_budget_hit', 'calculation_ms', 'totals', 'sheets', 'stock_usage', 'unplaced'], array_keys($array));
        self::assertSame(count($result->sheets), $array['totals']['sheets']);
        self::assertSame($result->materialM2, $array['totals']['material_m2']);
        self::assertSame($result->sealMm, $array['totals']['seal_mm']);
        self::assertSame(['sheets', 'material_m2', 'utilization_pct', 'waste_pct_avg', 'cut_count', 'cuts_mm', 'cuts_horizontal_mm', 'cuts_vertical_mm', 'seal_mm', 'remnant_m2', 'remnant_pct', 'net_material_m2', 'unplaced'], array_keys($array['totals']));
        self::assertSame($result->netMaterialM2, $array['totals']['net_material_m2']);
        self::assertSame(round($result->materialM2 - $result->remnantM2, 4), $result->netMaterialM2);
        self::assertSame(['index', 'width', 'height', 'used_pct', 'seal_mm', 'pieces', 'cuts', 'remnants', 'remnant_m2', 'upsized_from'], array_keys($array['sheets'][0]));
        self::assertSame(['count', 'horizontal_mm', 'vertical_mm', 'total_mm', 'horizontal', 'vertical'], array_keys($array['sheets'][0]['cuts']));
        self::assertSame(['x', 'y', 'width', 'height', 'rotated', 'label'], array_keys($array['sheets'][0]['pieces'][0]));
        self::assertIsString(json_encode($array, JSON_THROW_ON_ERROR));

        // the 1.x shape is still available
        $legacy = $result->toLegacyArray();
        self::assertSame(count($result->sheets), $legacy['total_sheets']);
        self::assertSame($result->objective, $legacy['strategy']);
        self::assertSame($result->passes, $legacy['permutations_tested']);
    }
}
