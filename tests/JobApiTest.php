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
