<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\SheetNester;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests: the complete calculate() output (placements, cuts, remnants,
 * totals) and the SVG previews of the reference job must stay byte-for-byte
 * identical. A difference means the algorithm or the renderer changed –
 * intended or not.
 *
 * To accept a deliberate change, regenerate the fixtures and review the diff:
 *   UPDATE_GOLDEN=1 vendor/bin/phpunit --filter GoldenTest
 */
final class GoldenTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures';

    /**
     * Fixture name => [constructor overrides, stock per format, extra pieces].
     *
     * @return array<string, array{array<string, mixed>, int|null, array<int, array{int, int, int}>}>
     */
    private static function cases(): array
    {
        return [
            // README reference: 18 sheets / 101.92 m²
            'min_waste-r200' => [[], null, []],

            'smallest_fit' => [
                ['formatStrategy' => SheetNester::STRATEGY_SMALLEST_FIT],
                null, [],
            ],
            'min_cuts-r20' => [
                ['formatStrategy' => SheetNester::STRATEGY_MIN_CUTS, 'restarts' => 20],
                null, [],
            ],
            'best_remnant-r20-defined' => [
                [
                    'formatStrategy' => SheetNester::STRATEGY_BEST_REMNANT,
                    'restarts'       => 20,
                    'remnantMode'    => SheetNester::REMNANT_DEFINED,
                ],
                null, [],
            ],
            // kerf + free rotation, remnants off
            'min_waste-r20-kerf4-none' => [
                [
                    'restarts'       => 20,
                    'kerf'           => 4.0,
                    'grainDirection' => 'none',
                    'remnantMode'    => SheetNester::REMNANT_NONE,
                ],
                null, [],
            ],
            // one sheet per format + 30 big pieces → stock shortage, plus one oversize piece
            'min_waste-r20-unplaced' => [
                ['restarts' => 20],
                1,
                [[2000, 2000, 30], [7000, 100, 1]],
            ],
        ];
    }

    public function testReferenceRunMatchesReadme(): void
    {
        $result = GoldenDataset::nester()->calculate();

        self::assertSame(18, $result['total_sheets']);
        self::assertSame(101.92, $result['total_material_m2']);
        self::assertSame([], $result['unplaced_pieces']);
        self::assertSame(203, $result['permutations_tested']);   // FFD + 2 distinct portfolio orderings + 200 shuffles
        self::assertFalse($result['time_budget_hit']);
    }

    public function testFixtures(): void
    {
        $update = in_array(getenv('UPDATE_GOLDEN'), ['1', 'true', 'yes'], true);
        if ($update && !is_dir(self::FIXTURE_DIR)) {
            mkdir(self::FIXTURE_DIR, 0777, true);
        }

        foreach (self::cases() as $name => [$options, $stock, $extraPieces]) {
            $nester = GoldenDataset::nester($options, $stock, $extraPieces);
            $actual = GoldenDataset::normalize($nester->calculate());

            // SVG previews: full markup of the first sheet (readable diff) + md5 of every sheet
            $svgMd5 = [];
            for ($i = 0; $i < $actual['total_sheets']; $i++) {
                $svgMd5[] = md5($nester->toSvg($i));
            }

            $resultFile = self::FIXTURE_DIR . "/{$name}.json";
            $svgMd5File = self::FIXTURE_DIR . "/{$name}.svg-md5.json";
            $sheet0File = self::FIXTURE_DIR . "/{$name}.sheet0.svg";

            if ($update) {
                file_put_contents($resultFile, GoldenDataset::encode($actual) . "\n");
                file_put_contents($svgMd5File, json_encode($svgMd5, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
                file_put_contents($sheet0File, $nester->toSvg(0));
                continue;
            }

            $hint = 'If the change is intended, regenerate with UPDATE_GOLDEN=1 vendor/bin/phpunit '
                . '--filter GoldenTest and review the diff.';

            self::assertFileExists($resultFile, "Missing fixture '{$name}'. {$hint}");
            $expected = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $actual, "Golden fixture '{$name}' differs from the current output. {$hint}");

            self::assertSame(
                (string) file_get_contents($sheet0File),
                $nester->toSvg(0),
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
