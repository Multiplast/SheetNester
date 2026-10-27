<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\SheetNester;

/**
 * The reference job ("golden dataset") – the default prefill of Bridge
 * /admin/nesting/: 30 stock formats (3 heights × 10 widths, 20 sheets each),
 * 7 piece lines (82 pieces) and three remnant formats.
 *
 * With OPTIONS as given (min_waste, 200 restarts, seed 42, no time budget) the
 * engine must produce 18 sheets / 101.92 m². Every fixture in tests/fixtures is
 * derived from this job by overriding options, stock or adding pieces.
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

    /** @var array<int, array{int, int}>  Registered for REMNANT_DEFINED runs. */
    public const REMNANT_FORMATS = [[2000, 1050], [1000, 1050], [500, 500]];

    /** Constructor arguments of the reference run. */
    public const OPTIONS = [
        'kerf'           => 0.0,
        'grainDirection' => 'vertical',
        'formatStrategy' => SheetNester::STRATEGY_MIN_WASTE,
        'restarts'       => 200,
        'seed'           => 42,
        'timeBudgetMs'   => null,
        'remnantMode'    => SheetNester::REMNANT_AUTO,
        'minRemnantSide' => 200.0,
        'cutCostM2'      => 0.05,
    ];

    /**
     * @param array<string, mixed>            $options      Overrides for OPTIONS (constructor arguments).
     * @param int|null                        $stock        Sheets per format (null = STOCK).
     * @param array<int, array{int, int, int}> $extraPieces  Additional [width, height, qty] lines.
     */
    public static function nester(array $options = [], ?int $stock = null, array $extraPieces = []): SheetNester
    {
        $o = array_merge(self::OPTIONS, $options);

        // Explicit named arguments – unpacking a string-keyed array needs PHP 8.1.
        $nester = new SheetNester(
            kerf:           $o['kerf'],
            grainDirection: $o['grainDirection'],
            formatStrategy: $o['formatStrategy'],
            restarts:       $o['restarts'],
            seed:           $o['seed'],
            timeBudgetMs:   $o['timeBudgetMs'],
            remnantMode:    $o['remnantMode'],
            minRemnantSide: $o['minRemnantSide'],
            cutCostM2:      $o['cutCostM2'],
        );

        foreach (self::HEIGHTS as $height) {
            foreach (self::WIDTHS as $width) {
                $nester->addStockFormat($width, $height, $stock ?? self::STOCK);
            }
        }
        foreach (array_merge(self::PIECES, $extraPieces) as [$w, $h, $qty]) {
            $nester->addPiece($w, $h, $qty);
        }
        foreach (self::REMNANT_FORMATS as [$w, $h]) {
            $nester->addRemnantFormat($w, $h);
        }

        return $nester;
    }

    /**
     * Result array without the run-dependent timing, passed through JSON so
     * floats are normalised exactly like the stored fixtures.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function normalize(array $result): array
    {
        unset($result['calculation_time_ms']);
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
