<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Render\SvgRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests: the complete output of the reference job (placements, cuts,
 * remnants, totals – stored in the 1.x array shape, which 2.0 reproduces
 * bit for bit via toLegacyArray()) and its SVG previews must stay identical.
 * A difference means the algorithm or the renderer changed – intended or not.
 *
 * To accept a deliberate change, regenerate the fixtures and review the diff:
 *   UPDATE_GOLDEN=1 vendor/bin/phpunit --filter GoldenTest
 */
final class GoldenTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures';

    /**
     * Fixture name => [option overrides, stock per format, extra pieces].
     *
     * @return array<string, array{array<string, mixed>, int, array<int, array{int, int, int}>}>
     */
    private static function cases(): array
    {
        return [
            // README reference: 18 sheets / 101.92 m²
            'min_waste-r200' => [[], GoldenDataset::STOCK, []],

            'min_waste-single-pass' => [
                ['search' => Search::singlePass()],
                GoldenDataset::STOCK, [],
            ],
            'min_cuts-r20' => [
                ['objective' => Objective::minCuts(0.05), 'search' => new Search(restarts: 20, seed: 42)],
                GoldenDataset::STOCK, [],
            ],
            'best_remnant-r20-defined' => [
                [
                    'objective' => Objective::bestRemnant(),
                    'search'    => new Search(restarts: 20, seed: 42),
                    'remnants'  => Remnants::defined(GoldenDataset::REMNANT_FORMATS),
                ],
                GoldenDataset::STOCK, [],
            ],
            // kerf + free rotation, remnants off
            'min_waste-r20-kerf4-none' => [
                [
                    'material' => new Material(kerf: 4.0, grain: Grain::NONE),
                    'search'   => new Search(restarts: 20, seed: 42),
                    'remnants' => Remnants::none(),
                ],
                GoldenDataset::STOCK, [],
            ],
            // one sheet per format + 30 big pieces → stock shortage, plus one oversize piece
            'min_waste-r20-unplaced' => [
                ['search' => new Search(restarts: 20, seed: 42)],
                1,
                [[2000, 2000, 30], [7000, 100, 1]],
            ],
            // minimum remnant size in both dimensions + larger formats for usable remnants
            'min_waste-r20-atleast-upsize' => [
                [
                    'search'   => new Search(restarts: 20, seed: 42),
                    'remnants' => Remnants::atLeast(1000, 1000, upsize: true),
                ],
                GoldenDataset::STOCK, [],
            ],
        ];
    }

    public function testReferenceRunMatchesReadme(): void
    {
        $result = GoldenDataset::solve();

        self::assertCount(18, $result->sheets);
        self::assertSame(101.92, $result->materialM2);
        self::assertSame([], $result->unplaced);
        self::assertSame(203, $result->passes);   // FFD + 2 distinct portfolio orderings + 200 shuffles
        self::assertFalse($result->timeBudgetHit);
    }

    public function testFixtures(): void
    {
        $update = in_array(getenv('UPDATE_GOLDEN'), ['1', 'true', 'yes'], true);
        if ($update && !is_dir(self::FIXTURE_DIR)) {
            mkdir(self::FIXTURE_DIR, 0777, true);
        }
        $renderer = new SvgRenderer();

        foreach (self::cases() as $name => [$options, $stock, $extraPieces]) {
            $result = GoldenDataset::solve($options, $stock, $extraPieces);
            $actual = GoldenDataset::normalize($result->toLegacyArray());

            // SVG previews: full markup of the first sheet (readable diff) + md5 of every sheet
            $svgMd5 = [];
            foreach ($result->sheets as $sheet) {
                $svgMd5[] = md5($renderer->render($sheet));
            }

            $resultFile = self::FIXTURE_DIR . "/{$name}.json";
            $svgMd5File = self::FIXTURE_DIR . "/{$name}.svg-md5.json";
            $sheet0File = self::FIXTURE_DIR . "/{$name}.sheet0.svg";

            if ($update) {
                file_put_contents($resultFile, GoldenDataset::encode($actual) . "\n");
                file_put_contents($svgMd5File, json_encode($svgMd5, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                file_put_contents($sheet0File, $renderer->render($result->sheets[0]));
                continue;
            }

            $hint = 'If the change is intended, regenerate with UPDATE_GOLDEN=1 vendor/bin/phpunit '
                . '--filter GoldenTest and review the diff.';

            self::assertFileExists($resultFile, "Missing fixture '{$name}'. {$hint}");
            $expected = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $actual, "Golden fixture '{$name}' differs from the current output. {$hint}");

            self::assertSame(
                (string) file_get_contents($sheet0File),
                $renderer->render($result->sheets[0]),
                "SVG preview of '{$name}' (sheet 0) changed. {$hint}"
            );
            self::assertSame(
                json_decode((string) file_get_contents($svgMd5File), true, 512, JSON_THROW_ON_ERROR),
                $svgMd5,
                "SVG previews of '{$name}' changed. {$hint}"
            );
        }

        if ($update) {
            self::markTestSkipped('Golden fixtures regenerated in ' . self::FIXTURE_DIR . ' – review the diff.');
        }
    }
}
