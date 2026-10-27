<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\SheetNester;
use PHPUnit\Framework\TestCase;

/**
 * Property-based checks on seeded random jobs. Whatever the strategy, a result
 * must be geometrically valid (pieces inside the sheet, no overlaps, kerf gaps
 * respected), conserve every input piece, respect stock limits, mark remnants
 * only in free space and report totals that agree with the per-sheet data.
 *
 * The multi-pass strategies must also never be worse than the single FFD pass
 * they start from (their pass 0 IS the smallest_fit pipeline).
 */
final class InvariantsTest extends TestCase
{
    private const INSTANCES = 40;
    private const EPS       = 1e-6;

    private const STRATEGIES = [
        SheetNester::STRATEGY_SMALLEST_FIT,
        SheetNester::STRATEGY_MIN_WASTE,
        SheetNester::STRATEGY_MIN_CUTS,
        SheetNester::STRATEGY_BEST_REMNANT,
    ];

    private const REASONS = [
        'Nedostatek skladových zásob.',
        'Kus je větší než všechny dostupné formáty desek.',
    ];

    public function testRandomJobsSatisfyInvariants(): void
    {
        for ($seed = 1; $seed <= self::INSTANCES; $seed++) {
            $job      = self::randomJob($seed);
            $baseline = null;

            foreach (self::STRATEGIES as $strategy) {
                $label  = "seed {$seed} / {$strategy}";
                $result = self::nesterFor($job, $strategy, $seed)->calculate();

                $this->assertStructure($result, $strategy, $label);
                $this->assertGeometry($result, $job, $label);
                $this->assertConservation($result, $job, $label);
                $this->assertStock($result, $job, $label);
                $this->assertTotals($result, $label);
                $this->assertCuts($result, $label);
                $this->assertRemnants($result, $job, $label);

                if ($strategy === SheetNester::STRATEGY_SMALLEST_FIT) {
                    $baseline = $result;
                } else {
                    $this->assertNotWorseThanSinglePass($result, $baseline, $strategy, $label);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Job generation
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function randomJob(int $seed): array
    {
        mt_srand($seed);

        $pool = [[6000, 2100], [4000, 2100], [3000, 1500], [2500, 1250], [2050, 1250]];
        shuffle($pool);
        $formats = [];
        for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
            $formats[] = [$pool[$i][0], $pool[$i][1], mt_rand(1, 6)];
        }

        $pieces = [];
        for ($i = 0, $n = mt_rand(3, 8); $i < $n; $i++) {
            // 100–2500 mm in 50 mm steps; some will not fit any format at all
            $pieces[] = [mt_rand(2, 50) * 50, mt_rand(2, 50) * 50, mt_rand(1, 6)];
        }

        return [
            'formats'        => $formats,
            'pieces'         => $pieces,
            'kerf'           => [0.0, 3.0][mt_rand(0, 1)],
            'grain'          => ['none', 'vertical'][mt_rand(0, 1)],
            'remnant'        => [SheetNester::REMNANT_NONE, SheetNester::REMNANT_AUTO, SheetNester::REMNANT_DEFINED][mt_rand(0, 2)],
            'remnantFormats' => [[1000, 500], [600, 600]],
            'minRemnantSide' => 200.0,
        ];
    }

    /** @param array<string, mixed> $job */
    private static function nesterFor(array $job, string $strategy, int $seed): SheetNester
    {
        $nester = new SheetNester(
            kerf:           $job['kerf'],
            grainDirection: $job['grain'],
            formatStrategy: $strategy,
            restarts:       5,
            seed:           $seed,
            remnantMode:    $job['remnant'],
            minRemnantSide: $job['minRemnantSide'],
        );
        foreach ($job['formats'] as [$w, $h, $stock]) {
            $nester->addStockFormat($w, $h, $stock);
        }
        foreach ($job['pieces'] as [$w, $h, $qty]) {
            $nester->addPiece($w, $h, $qty);
        }
        foreach ($job['remnantFormats'] as [$w, $h]) {
            $nester->addRemnantFormat($w, $h);
        }
        return $nester;
    }

    // -------------------------------------------------------------------------
    // Invariants
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $result */
    private function assertStructure(array $result, string $strategy, string $label): void
    {
        self::assertSame($strategy, $result['strategy'], $label);
        self::assertCount($result['total_sheets'], $result['sheets'], "{$label}: total_sheets");
        self::assertGreaterThanOrEqual(1, $result['permutations_tested'], $label);

        $prev = [PHP_FLOAT_MAX, PHP_FLOAT_MAX, PHP_FLOAT_MAX];
        foreach ($result['sheets'] as $i => $sheet) {
            self::assertSame($i, $sheet['sheet_index'], "{$label}: sheet_index");
            self::assertNotEmpty($sheet['pieces'], "{$label}: sheet {$i} has no pieces");

            // largest area first, ties: width desc, then height desc
            $cur = [$sheet['stock_width'] * $sheet['stock_height'], $sheet['stock_width'], $sheet['stock_height']];
            self::assertTrue($cur <= $prev, "{$label}: sheets must be ordered largest format first");
            $prev = $cur;
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $job
     */
    private function assertGeometry(array $result, array $job, string $label): void
    {
        $inputDims = [];
        foreach ($job['pieces'] as [$w, $h]) {
            $inputDims["{$w}x{$h}"] = true;
        }
        $kerf = $job['kerf'];

        foreach ($result['sheets'] as $sheet) {
            $sw = $sheet['stock_width'];
            $sh = $sheet['stock_height'];
            $pieces = $sheet['pieces'];

            foreach ($pieces as $p) {
                $where = "{$label}: piece {$p['width']}x{$p['height']} at ({$p['x']},{$p['y']}) on {$sw}x{$sh}";
                self::assertGreaterThan(0, $p['width'], $where);
                self::assertGreaterThan(0, $p['height'], $where);
                self::assertGreaterThanOrEqual(-self::EPS, $p['x'], $where);
                self::assertGreaterThanOrEqual(-self::EPS, $p['y'], $where);
                self::assertLessThanOrEqual($sw + self::EPS, $p['x'] + $p['width'], "{$where}: exceeds sheet width");
                self::assertLessThanOrEqual($sh + self::EPS, $p['y'] + $p['height'], "{$where}: exceeds sheet height");

                $asGiven  = isset($inputDims["{$p['width']}x{$p['height']}"]);
                $asTurned = isset($inputDims["{$p['height']}x{$p['width']}"]);
                if ($job['grain'] !== 'none') {
                    self::assertFalse($p['rotated'], "{$where}: rotated although grain is locked");
                    self::assertTrue($asGiven, "{$where}: dimensions not in the input");
                } else {
                    self::assertTrue($asGiven || $asTurned, "{$where}: dimensions not in the input");
                }
            }

            $n = count($pieces);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $pieces[$i];
                    $b = $pieces[$j];
                    $overlapX = min($a['x'] + $a['width'],  $b['x'] + $b['width'])  - max($a['x'], $b['x']);
                    $overlapY = min($a['y'] + $a['height'], $b['y'] + $b['height']) - max($a['y'], $b['y']);
                    $pair = "{$label}: pieces #{$i} and #{$j} on sheet {$sheet['sheet_index']}";

                    self::assertFalse(
                        $overlapX > self::EPS && $overlapY > self::EPS,
                        "{$pair} overlap"
                    );
                    if ($kerf > 0) {
                        // Neighbours must leave at least one kerf between them.
                        if ($overlapY > self::EPS) {
                            self::assertGreaterThanOrEqual($kerf - self::EPS, -$overlapX, "{$pair}: horizontal gap smaller than kerf");
                        } elseif ($overlapX > self::EPS) {
                            self::assertGreaterThanOrEqual($kerf - self::EPS, -$overlapY, "{$pair}: vertical gap smaller than kerf");
                        }
                    }
                }
            }
        }
    }

    /**
     * Every input piece ends up exactly once: placed or listed as unplaced.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $job
     */
    private function assertConservation(array $result, array $job, string $label): void
    {
        $free = $job['grain'] === 'none';
        $key  = static function (float $w, float $h) use ($free): string {
            return $free ? min($w, $h) . 'x' . max($w, $h) : "{$w}x{$h}";
        };

        $expected = [];
        foreach ($job['pieces'] as [$w, $h, $qty]) {
            $k = $key((float) $w, (float) $h);
            $expected[$k] = ($expected[$k] ?? 0) + $qty;
        }

        $actual = [];
        foreach ($result['sheets'] as $sheet) {
            foreach ($sheet['pieces'] as $p) {
                $k = $key($p['width'], $p['height']);
                $actual[$k] = ($actual[$k] ?? 0) + 1;
            }
        }
        foreach ($result['unplaced_pieces'] as $u) {
            self::assertGreaterThanOrEqual(1, $u['qty'], $label);
            self::assertContains($u['reason'], self::REASONS, "{$label}: unknown unplaced reason");
            $k = $key($u['width'], $u['height']);
            $actual[$k] = ($actual[$k] ?? 0) + $u['qty'];
        }

        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, "{$label}: pieces lost or invented");
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $job
     */
    private function assertStock(array $result, array $job, string $label): void
    {
        $stock = [];
        foreach ($job['formats'] as [$w, $h, $count]) {
            $stock["{$w}x{$h}"] = ($stock["{$w}x{$h}"] ?? 0) + $count;
        }

        $used = [];
        foreach ($result['sheets'] as $sheet) {
            $k = "{$sheet['stock_width']}x{$sheet['stock_height']}";
            self::assertArrayHasKey($k, $stock, "{$label}: sheet uses unknown format {$k}");
            $used[$k] = ($used[$k] ?? 0) + 1;
        }
        foreach ($used as $k => $n) {
            self::assertLessThanOrEqual($stock[$k], $n, "{$label}: stock of {$k} exceeded");
        }

        $usage = [];
        foreach ($result['stock_usage'] as $u) {
            $usage["{$u['width']}x{$u['height']}"] = $u;
        }
        // stock_usage order is unspecified (built before the sheets are sorted) – compare as sets
        $usedKeys  = array_keys($used);
        $usageKeys = array_keys($usage);
        sort($usedKeys);
        sort($usageKeys);
        self::assertSame($usedKeys, $usageKeys, "{$label}: stock_usage lists other formats than the sheets");
        foreach ($used as $k => $n) {
            self::assertSame($n, $usage[$k]['used'], "{$label}: stock_usage.used of {$k}");
            self::assertSame($stock[$k] - $n, $usage[$k]['remaining'], "{$label}: stock_usage.remaining of {$k}");
        }
    }

    /** @param array<string, mixed> $result */
    private function assertTotals(array $result, string $label): void
    {
        $material  = 0.0;
        $pieceArea = 0.0;
        foreach ($result['sheets'] as $sheet) {
            $material += $sheet['stock_width'] * $sheet['stock_height'] / 1_000_000;
            foreach ($sheet['pieces'] as $p) {
                $pieceArea += $p['width'] * $p['height'] / 1_000_000;
            }
        }

        self::assertEqualsWithDelta(round($material, 4), $result['total_material_m2'], 1e-9, "{$label}: total_material_m2");
        $util = $material > 0 ? round($pieceArea / $material * 100, 2) : 0.0;
        self::assertEqualsWithDelta($util, $result['utilization_pct'], 1e-9, "{$label}: utilization_pct");
        self::assertGreaterThanOrEqual(0.0, $result['total_waste_pct'], $label);
        self::assertLessThanOrEqual(100.0, $result['total_waste_pct'], $label);
    }

    /** @param array<string, mixed> $result */
    private function assertCuts(array $result, string $label): void
    {
        $count = 0;
        $mm    = 0.0;

        foreach ($result['sheets'] as $sheet) {
            $sw = $sheet['stock_width'];
            $sh = $sheet['stock_height'];
            $c  = $sheet['cuts'];

            self::assertEqualsWithDelta($c['horizontal_mm'] + $c['vertical_mm'], $c['total_mm'], 0.051, "{$label}: cuts.total_mm");

            foreach ($c['cuts_horizontal'] as $seg) {
                $where = "{$label}: horizontal cut at y={$seg['y']}";
                self::assertGreaterThan(0.0, $seg['y'], $where);
                self::assertLessThan($sh, $seg['y'], $where);
                self::assertGreaterThanOrEqual(0.0, $seg['x_start'], $where);
                self::assertLessThan($seg['x_end'], $seg['x_start'], $where);
                self::assertLessThanOrEqual($sw + self::EPS, $seg['x_end'], $where);
                self::assertEqualsWithDelta(round($seg['x_end'] - $seg['x_start'], 1), $seg['length'], 1e-9, $where);
            }
            foreach ($c['cuts_vertical'] as $seg) {
                $where = "{$label}: vertical cut at x={$seg['x']}";
                self::assertGreaterThan(0.0, $seg['x'], $where);
                self::assertLessThan($sw, $seg['x'], $where);
                self::assertGreaterThanOrEqual(0.0, $seg['y_start'], $where);
                self::assertLessThan($seg['y_end'], $seg['y_start'], $where);
                self::assertLessThanOrEqual($sh + self::EPS, $seg['y_end'], $where);
                self::assertEqualsWithDelta(round($seg['y_end'] - $seg['y_start'], 1), $seg['length'], 1e-9, $where);
            }

            $count += count($c['cuts_horizontal']) + count($c['cuts_vertical']);
            $mm    += $c['horizontal_mm'] + $c['vertical_mm'];
        }

        self::assertSame($count, $result['total_cuts_count'], "{$label}: total_cuts_count");
        self::assertEqualsWithDelta(round($mm, 1), $result['total_cuts_mm'], 0.051, "{$label}: total_cuts_mm");
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $job
     */
    private function assertRemnants(array $result, array $job, string $label): void
    {
        if ($job['remnant'] === SheetNester::REMNANT_NONE) {
            self::assertArrayNotHasKey('total_remnant_m2', $result, $label);
            foreach ($result['sheets'] as $sheet) {
                self::assertArrayNotHasKey('remnants', $sheet, $label);
            }
            return;
        }

        $allowed = [];
        foreach ($job['remnantFormats'] as [$w, $h]) {
            $allowed["{$w}x{$h}"] = true;
            if ($job['grain'] === 'none') {
                $allowed["{$h}x{$w}"] = true;
            }
        }
        $kerf  = $job['kerf'];
        $total = 0.0;

        foreach ($result['sheets'] as $sheet) {
            self::assertArrayHasKey('remnants', $sheet, $label);
            $sw   = $sheet['stock_width'];
            $sh   = $sheet['stock_height'];
            $rs   = $sheet['remnants'];
            $area = 0.0;

            self::assertLessThanOrEqual(10, count($rs), "{$label}: more than 10 remnants on one sheet");

            foreach ($rs as $i => $r) {
                $where = "{$label}: remnant {$r['width']}x{$r['height']} at ({$r['x']},{$r['y']}) on sheet {$sheet['sheet_index']}";
                self::assertGreaterThanOrEqual(-self::EPS, $r['x'], $where);
                self::assertGreaterThanOrEqual(-self::EPS, $r['y'], $where);
                self::assertLessThanOrEqual($sw + self::EPS, $r['x'] + $r['width'], $where);
                self::assertLessThanOrEqual($sh + self::EPS, $r['y'] + $r['height'], $where);

                if ($job['remnant'] === SheetNester::REMNANT_AUTO) {
                    self::assertGreaterThanOrEqual($job['minRemnantSide'] - self::EPS, min($r['width'], $r['height']), "{$where}: shorter than minRemnantSide");
                } else {
                    self::assertArrayHasKey("{$r['width']}x{$r['height']}", $allowed, "{$where}: not a registered remnant format");
                }

                // must lie in free space: no intersection with kerf-inflated piece footprints
                foreach ($sheet['pieces'] as $p) {
                    $fw = min($p['width']  + $kerf, $sw - $p['x']);
                    $fh = min($p['height'] + $kerf, $sh - $p['y']);
                    self::assertFalse(
                        self::intersects($r['x'], $r['y'], $r['width'], $r['height'], $p['x'], $p['y'], $fw, $fh),
                        "{$where}: overlaps piece {$p['width']}x{$p['height']} at ({$p['x']},{$p['y']})"
                    );
                }
                foreach ($rs as $j => $o) {
                    if ($j > $i) {
                        self::assertFalse(
                            self::intersects($r['x'], $r['y'], $r['width'], $r['height'], $o['x'], $o['y'], $o['width'], $o['height']),
                            "{$where}: overlaps another remnant"
                        );
                    }
                }
                $area += $r['width'] * $r['height'] / 1_000_000;
            }

            self::assertEqualsWithDelta(round($area, 4), $sheet['remnant_area_m2'], 1e-9, "{$label}: remnant_area_m2");
            $total += $area;
        }

        self::assertEqualsWithDelta(round($total, 4), $result['total_remnant_m2'], 1e-9, "{$label}: total_remnant_m2");
        $pct = $result['total_material_m2'] > 0 ? round($total / $result['total_material_m2'] * 100, 2) : 0.0;
        self::assertEqualsWithDelta($pct, $result['total_remnant_pct'], 0.011, "{$label}: total_remnant_pct");
    }

    /**
     * Pass 0 of every multi-pass strategy is the smallest_fit pipeline, and the
     * best candidate is chosen by (fewest unplaced, lowest primary cost) – so the
     * multi-pass result can never be worse than the single pass on that key.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $baseline
     */
    private function assertNotWorseThanSinglePass(array $result, array $baseline, string $strategy, string $label): void
    {
        $unplaced = static fn(array $r): int => (int) array_sum(array_column($r['unplaced_pieces'], 'qty'));

        self::assertLessThanOrEqual($unplaced($baseline), $unplaced($result), "{$label}: more unplaced than smallest_fit");
        if ($unplaced($result) !== $unplaced($baseline)) {
            return;
        }

        if ($strategy === SheetNester::STRATEGY_MIN_CUTS) {
            // default cutCostM2 = 0.05
            $cost = static fn(array $r): float => $r['total_material_m2'] + 0.05 * $r['total_cuts_count'];
            self::assertLessThanOrEqual($cost($baseline) + 1e-9, $cost($result), "{$label}: weighted cost worse than smallest_fit");
        } else {
            self::assertLessThanOrEqual($baseline['total_material_m2'] + 1e-9, $result['total_material_m2'], "{$label}: more material than smallest_fit");
        }
    }

    private static function intersects(float $ax, float $ay, float $aw, float $ah, float $bx, float $by, float $bw, float $bh): bool
    {
        return $ax < $bx + $bw - self::EPS && $ax + $aw > $bx + self::EPS
            && $ay < $by + $bh - self::EPS && $ay + $ah > $by + self::EPS;
    }
}
