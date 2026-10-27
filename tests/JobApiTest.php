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
use Multiplast\SheetNester\Render\SvgRenderer;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\SheetNester;
use PHPUnit\Framework\TestCase;

/**
 * The 2.0 job API must give exactly what the 1.x facade gives for the same
 * input – the golden fixtures are the reference for both.
 */
final class JobApiTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures';

    /** Reference job of GoldenDataset expressed with the 2.0 API. */
    private static function referenceJob(Search $search, ?Objective $objective = null, ?Remnants $remnants = null): NestingJob
    {
        $job = NestingJob::create(
            material:  new Material(kerf: 0, grain: Grain::ALONG_HEIGHT),   // 1.x 'vertical'
            objective: $objective ?? Objective::minWaste(),
            search:    $search,
            remnants:  $remnants ?? Remnants::auto(minSide: 200),
        );
        foreach (GoldenDataset::HEIGHTS as $height) {
            foreach (GoldenDataset::WIDTHS as $width) {
                $job->addStock($width, $height, GoldenDataset::STOCK);
            }
        }
        foreach (GoldenDataset::PIECES as [$w, $h, $qty]) {
            $job->addPiece($w, $h, $qty);
        }
        return $job;
    }

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE_DIR . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSolveMatchesTheGoldenFixture(): void
    {
        $result = (new SheetNester())->solve(self::referenceJob(new Search(restarts: 200, seed: 42)));

        self::assertInstanceOf(NestingResult::class, $result);
        self::assertSame(18, count($result->sheets));
        self::assertSame(101.92, $result->materialM2);
        self::assertSame([], $result->unplaced);
        self::assertSame(Objective::MIN_WASTE, $result->objective);

        self::assertSame(self::fixture('min_waste-r200'), GoldenDataset::normalize($result->toLegacyArray()));
    }

    public function testSinglePassMatchesSmallestFit(): void
    {
        $result = (new SheetNester())->solve(self::referenceJob(Search::singlePass()));

        self::assertSame(1, $result->passes);
        self::assertFalse($result->timeBudgetHit);

        $expected = self::fixture('smallest_fit');
        $expected['strategy'] = Objective::MIN_WASTE;   // 1.x reported 'smallest_fit' as the strategy
        self::assertSame($expected, GoldenDataset::normalize($result->toLegacyArray()));
    }

    public function testMinCutsAndBestRemnantMatchTheirFixtures(): void
    {
        $minCuts = (new SheetNester())->solve(self::referenceJob(new Search(restarts: 20, seed: 42), Objective::minCuts(0.05)));
        self::assertSame(self::fixture('min_cuts-r20'), GoldenDataset::normalize($minCuts->toLegacyArray()));

        $bestRemnant = (new SheetNester())->solve(self::referenceJob(
            new Search(restarts: 20, seed: 42),
            Objective::bestRemnant(),
            Remnants::defined(GoldenDataset::REMNANT_FORMATS)
        ));
        self::assertSame(self::fixture('best_remnant-r20-defined'), GoldenDataset::normalize($bestRemnant->toLegacyArray()));
    }

    public function testSvgRendererMatchesTheGoldenPreview(): void
    {
        $result = (new SheetNester())->solve(self::referenceJob(new Search(restarts: 200, seed: 42)));

        self::assertSame(
            (string) file_get_contents(self::FIXTURE_DIR . '/min_waste-r200.sheet0.svg'),
            (new SvgRenderer())->render($result->sheets[0])
        );
    }

    public function testResultObjectIsConsistentWithItsLegacyArray(): void
    {
        $result = (new SheetNester())->solve(self::referenceJob(Search::singlePass()));
        $legacy = $result->toLegacyArray();

        foreach ($result->sheets as $i => $sheet) {
            self::assertSame($i, $sheet->index);
            self::assertSame($legacy['sheets'][$i]['stock_width'], $sheet->width);
            self::assertCount(count($legacy['sheets'][$i]['pieces']), $sheet->pieces);
            self::assertSame($legacy['sheets'][$i]['cuts']['total_mm'], $sheet->cuts->totalMm);
            self::assertSame($legacy['sheets'][$i]['remnant_area_m2'], $sheet->remnantM2);
        }
        self::assertSame($legacy['total_cuts_count'], $result->cutCount);
        self::assertSame($legacy['total_remnant_m2'], $result->remnantM2);
        self::assertSame($legacy['utilization_pct'], $result->utilizationPct);
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
        self::assertSame([2, 'stock'], $unplaced['']);          // two unlabeled 900×400 merged like 1.x
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
        $result = (new SheetNester())->solve(self::referenceJob(Search::singlePass()));
        $array  = $result->toArray();

        self::assertSame(['objective', 'passes', 'time_budget_hit', 'calculation_ms', 'totals', 'sheets', 'stock_usage', 'unplaced'], array_keys($array));
        self::assertSame(count($result->sheets), $array['totals']['sheets']);
        self::assertSame($result->materialM2, $array['totals']['material_m2']);
        self::assertSame($result->sealMm, $array['totals']['seal_mm']);
        self::assertSame(['index', 'width', 'height', 'used_pct', 'seal_mm', 'pieces', 'cuts', 'remnants', 'remnant_m2'], array_keys($array['sheets'][0]));
        self::assertSame(['count', 'horizontal_mm', 'vertical_mm', 'total_mm', 'horizontal', 'vertical'], array_keys($array['sheets'][0]['cuts']));
        self::assertSame(['x', 'y', 'width', 'height', 'rotated', 'label'], array_keys($array['sheets'][0]['pieces'][0]));
        self::assertIsString(json_encode($array, JSON_THROW_ON_ERROR));
    }

    public function testOptionsValidateTheirArguments(): void
    {
        $invalid = [
            'negative kerf'        => fn() => new Material(kerf: -1),
            'unknown grain'        => fn() => new Material(grain: 'vertical'),
            'negative cut cost'    => fn() => Objective::minCuts(-0.1),
            'negative restarts'    => fn() => new Search(restarts: -1),
            'zero time budget'     => fn() => new Search(timeBudgetMs: 0),
            'zero remnant side'    => fn() => Remnants::auto(0),
            'no remnant formats'   => fn() => Remnants::defined([]),
            'bad remnant format'   => fn() => Remnants::defined([[1000, 0]]),
            'stock quantity 0'     => fn() => NestingJob::create()->addStock(1000, 1000, 0),
            'piece width 0'        => fn() => NestingJob::create()->addPiece(0, 100),
            'piece quantity 0'     => fn() => NestingJob::create()->addPiece(100, 100, 0),
        ];
        foreach ($invalid as $label => $call) {
            try {
                $call();
                self::fail("Accepted invalid {$label}");
            } catch (InvalidArgumentException $e) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testJobDefaults(): void
    {
        $job = NestingJob::create();

        self::assertSame(0.0, $job->material()->kerf);
        self::assertSame(Grain::NONE, $job->material()->grain);
        self::assertTrue($job->material()->allowsRotation());
        self::assertSame(Objective::MIN_WASTE, $job->objective()->name);
        self::assertSame(20, $job->search()->restarts);
        self::assertFalse($job->search()->isSinglePass());
        self::assertFalse($job->remnants()->enabled());
    }

    public function testLegacyMethodsRequireLegacyConstructor(): void
    {
        $this->expectException(\LogicException::class);
        (new SheetNester())->addPiece(100, 100, 1);
    }
}
