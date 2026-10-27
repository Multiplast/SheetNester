<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\SheetNester;

/**
 * The reference job ("golden dataset"): 30 stock formats (3 heights × 10
 * widths, 20 sheets each), 7 piece lines (82 pieces) and three remnant
 * formats – the default prefill of the Bridge nesting admin it came from.
 *
 * With the default options (least material, 200 restarts, seed 42, no time
 * budget, channels along the height, auto remnants ≥ 200 mm) the engine must
 * produce 18 sheets / 101.92 m². Every fixture in tests/fixtures is derived
 * from this job by overriding options, stock or adding pieces.
 */
final class GoldenDataset
{
    public const HEIGHTS = [2100, 1820, 1050];
    public const WIDTHS  = [6000, 5000, 4500, 4000, 3500, 3000, 2500, 2000, 1500, 1000];
    public const STOCK   = 20;

    /** @var array<int, array{int, int, int}>  [width, height, qty] */
    public const PIECES = [
        [1000, 1050, 1],
        [2000, 1050, 6],
        [2200, 1260, 2],
        [900,  810,  40],
        [270,  810,  10],
        [1890, 750,  1],
        [2200, 900,  22],
    ];

    /** @var array<int, array{int, int}>  Used by the Remnants::defined() fixture. */
    public const REMNANT_FORMATS = [[2000, 1050], [1000, 1050], [500, 500]];

    /**
     * @param array<string, mixed>             $options     Overrides: material, objective, search, remnants.
     * @param int                              $stock       Sheets per format.
     * @param array<int, array{int, int, int}> $extraPieces Additional [width, height, qty] lines.
     */
    public static function job(array $options = [], int $stock = self::STOCK, array $extraPieces = []): NestingJob
    {
        $job = NestingJob::create(
            $options['material']  ?? new Material(kerf: 0.0, grain: Grain::ALONG_HEIGHT),
            $options['objective'] ?? Objective::minWaste(),
            $options['search']    ?? new Search(restarts: 200, seed: 42),
            $options['remnants']  ?? Remnants::auto(200.0),
        );
        foreach (self::HEIGHTS as $height) {
            foreach (self::WIDTHS as $width) {
                $job->addStock($width, $height, $stock);
            }
        }
        foreach (array_merge(self::PIECES, $extraPieces) as [$w, $h, $qty]) {
            $job->addPiece($w, $h, $qty);
        }
        return $job;
    }

    /** @param array<string, mixed> $options  See job(). */
    public static function solve(array $options = [], int $stock = self::STOCK, array $extraPieces = []): NestingResult
    {
        return (new SheetNester())->solve(self::job($options, $stock, $extraPieces));
    }

    /**
     * Array without the run-dependent timing, passed through JSON so floats
     * are normalised exactly like the stored fixtures.
     *
     * @param array<string, mixed> $result  toLegacyArray() or toArray() output.
     * @return array<string, mixed>
     */
    public static function normalize(array $result): array
    {
        unset($result['calculation_time_ms'], $result['calculation_ms']);
        return json_decode(self::encode($result), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }
}
