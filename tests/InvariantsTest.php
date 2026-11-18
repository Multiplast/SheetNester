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
use Multiplast\SheetNester\Options\UpsizeMethod;
use Multiplast\SheetNester\Result\Cut;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\Result\Placement;
use Multiplast\SheetNester\Result\SheetPlan;
use Multiplast\SheetNester\Result\UnplacedPieces;
use Multiplast\SheetNester\SheetNester;
use PHPUnit\Framework\TestCase;

/**
 * Property-based checks on seeded random jobs. Whatever the objective, a
 * result must be geometrically valid (pieces inside the sheet, no overlaps,
 * kerf gaps respected), conserve every input piece, respect stock limits,
 * mark remnants only in free space and report totals that agree with the
 * per-sheet data.
 *
 * Every multi-pass search starts from the single FFD pass, so its result can
 * never be worse than that pass on (unplaced pieces, primary cost).
 */
final class InvariantsTest extends TestCase
{
    private const INSTANCES = 40;
    private const EPS       = 1e-6;

    public function testRandomJobsSatisfyInvariants(): void
    {
        for ($seed = 1; $seed <= self::INSTANCES; $seed++) {
            $spec     = self::randomSpec($seed);
            $baseline = (new SheetNester())->solve(self::job($spec, Objective::minWaste(), Search::singlePass()));
            $this->assertAll($baseline, $spec, Objective::MIN_WASTE, "seed {$seed} / single pass");

            foreach ([Objective::minWaste(), Objective::minCuts(0.05), Objective::bestRemnant()] as $objective) {
                $label  = "seed {$seed} / {$objective->name}";
                $result = (new SheetNester())->solve(self::job($spec, $objective, new Search(restarts: 5, seed: $seed)));

                $this->assertAll($result, $spec, $objective->name, $label);
                $this->assertNotWorseThanSinglePass($result, $baseline, $objective, $spec['upsize']->enabled(), $label);
            }
        }
    }

    /**
     * UpsizeMethod::MULTIPLE on stock where some formats hold others exactly
     * or with a leftover, random method settings and order.
     */
    public function testRandomJobsWithMultiplesSatisfyInvariants(): void
    {
        $nestingRuns = 0;
        for ($seed = 1; $seed <= self::INSTANCES; $seed++) {
            $spec = self::randomMultipleSpec($seed);
            foreach ([Search::singlePass(), new Search(restarts: 3, seed: $seed)] as $search) {
                $label  = "multiples seed {$seed} / " . ($search->restarts === 0 ? 'single pass' : 'restarts');
                $result = (new SheetNester())->solve(self::job($spec, Objective::minWaste(), $search));
                $this->assertAll($result, $spec, Objective::MIN_WASTE, $label);
                foreach ($result->sheets as $sheet) {
                    if ($sheet->upsizeMethod === UpsizeMethod::MULTIPLE) {
                        $nestingRuns++;
                        break;
                    }
                }
            }
        }
        // the generator must actually exercise the method
        self::assertGreaterThanOrEqual(self::INSTANCES / 2, $nestingRuns, 'too few runs nested a sheet');
    }

    // -------------------------------------------------------------------------
    // Job generation
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function randomMultipleSpec(int $seed): array
    {
        mt_srand($seed);

        // small formats, each with one or two larger ones that hold it a few
        // times – exactly or with a leftover (a duplicate pools its stock)
        $children = [[3500, 2100], [2000, 1050], [1900, 2100], [2000, 1200], [1500, 1000]];
        $grids    = [[2, 1], [1, 2], [2, 2], [3, 1]];
        $extras   = [0, 0, 150, 800];
        shuffle($children);
        $formats = [];
        for ($i = 0, $n = mt_rand(1, 2); $i < $n; $i++) {
            [$cw, $ch] = $children[$i];
            $formats[] = [$cw, $ch, mt_rand(2, 8)];
            for ($k = 0, $m = mt_rand(1, 2); $k < $m; $k++) {
                [$kx, $ky] = $grids[mt_rand(0, 3)];
                $formats[] = [$kx * $cw + $extras[mt_rand(0, 3)], $ky * $ch + $extras[mt_rand(0, 3)], mt_rand(1, 4)];
            }
        }

        $pieces = [];
        for ($i = 0, $n = mt_rand(2, 5); $i < $n; $i++) {
            // small enough for the smaller formats, so those get opened
            $pieces[] = [mt_rand(2, 36) * 50, mt_rand(2, 20) * 50, mt_rand(1, 5)];
        }

        $remnantFormats = [[1000, 500], [600, 600]];
        $tolerances     = [0.0, 0.0, 200.0, 1000.0];
        $maxWidth       = mt_rand(1, 3);
        $multiple       = UpsizeMethod::multiple(
            $maxWidth,
            mt_rand($maxWidth === 1 ? 2 : 1, 3),
            $tolerances[mt_rand(0, 3)],
            $tolerances[mt_rand(0, 3)]
        );
        $methods = [
            [$multiple],
            [$multiple, UpsizeMethod::remnant(600.0, 300.0)],
            [UpsizeMethod::remnant(formats: $remnantFormats), $multiple],
        ][mt_rand(0, 2)];

        return [
            'formats'        => $formats,
            'pieces'         => $pieces,
            'kerf'           => [0.0, 3.0][mt_rand(0, 1)],
            'grain'          => [Grain::NONE, Grain::ALONG_WIDTH][mt_rand(0, 1)],
            'remnants'       => [
                Remnants::none(),
                Remnants::auto(200.0),
                Remnants::atLeast(600.0, 300.0),
                Remnants::defined($remnantFormats),
            ][mt_rand(0, 3)],
            'remnantFormats' => $remnantFormats,
            'upsize'         => mt_rand(0, 1) === 0 ? Upsize::lastSheet(methods: $methods) : Upsize::allSheets(methods: $methods),
        ];
    }

    /** @return array<string, mixed> */
    private static function randomSpec(int $seed): array
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

        $remnantFormats = [[1000, 500], [600, 600]];

        return [
            'formats'        => $formats,
            'pieces'         => $pieces,
            'kerf'           => [0.0, 3.0][mt_rand(0, 1)],
            'grain'          => [Grain::NONE, Grain::ALONG_HEIGHT][mt_rand(0, 1)],
            'remnants'       => [
                Remnants::none(),
                Remnants::auto(200.0),
                Remnants::atLeast(600.0, 300.0),
                Remnants::defined($remnantFormats),
            ][mt_rand(0, 3)],
            'remnantFormats' => $remnantFormats,
            // independent of the marking above
            'upsize'         => [
                Upsize::none(),
                Upsize::none(),
                Upsize::lastSheet(600.0, 300.0),
                Upsize::allSheets(600.0, 300.0),
                Upsize::lastSheet(formats: $remnantFormats),
                Upsize::allSheets(formats: $remnantFormats),
            ][mt_rand(0, 5)],
        ];
    }

    /** @param array<string, mixed> $spec */
    private static function job(array $spec, Objective $objective, Search $search): NestingJob
    {
        $job = NestingJob::create(new Material($spec['kerf'], $spec['grain']), $objective, $search, $spec['remnants'], $spec['upsize']);
        foreach ($spec['formats'] as [$w, $h, $stock]) {
            $job->addStock($w, $h, $stock);
        }
        foreach ($spec['pieces'] as [$w, $h, $qty]) {
            $job->addPiece($w, $h, $qty);
        }
        return $job;
    }

    // -------------------------------------------------------------------------
    // Invariants
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $spec */
    private function assertAll(NestingResult $result, array $spec, string $objective, string $label): void
    {
        $this->assertStructure($result, $objective, $label);
        $this->assertGeometry($result, $spec, $label);
        $this->assertConservation($result, $spec, $label);
        $this->assertStock($result, $spec, $label);
        $this->assertTotals($result, $label);
        $this->assertCuts($result, $label);
        $this->assertSeal($result, $spec, $label);
        $this->assertRemnants($result, $spec, $label);
        $this->assertUpsize($result, $spec, $label);
    }

    private function assertStructure(NestingResult $result, string $objective, string $label): void
    {
        self::assertSame($objective, $result->objective, $label);
        self::assertGreaterThanOrEqual(1, $result->passes, $label);

        $prev = [PHP_FLOAT_MAX, PHP_FLOAT_MAX, PHP_FLOAT_MAX];
        foreach ($result->sheets as $i => $sheet) {
            self::assertSame($i, $sheet->index, "{$label}: sheet index");
            self::assertNotEmpty($sheet->pieces, "{$label}: sheet {$i} has no pieces");

            // largest area first, ties: width desc, then height desc
            $cur = [$sheet->width * $sheet->height, $sheet->width, $sheet->height];
            self::assertTrue($cur <= $prev, "{$label}: sheets must be ordered largest format first");
            $prev = $cur;
        }
    }

    /** @param array<string, mixed> $spec */
    private function assertGeometry(NestingResult $result, array $spec, string $label): void
    {
        $inputDims = [];
        foreach ($spec['pieces'] as [$w, $h]) {
            $inputDims["{$w}x{$h}"] = true;
        }
        $kerf        = $spec['kerf'];
        $mayRotate   = $spec['grain'] === Grain::NONE;

        foreach ($result->sheets as $sheet) {
            $sw     = $sheet->width;
            $sh     = $sheet->height;
            $pieces = $sheet->pieces;

            foreach ($pieces as $p) {
                $where = "{$label}: piece {$p->width}x{$p->height} at ({$p->x},{$p->y}) on {$sw}x{$sh}";
                self::assertGreaterThan(0, $p->width, $where);
                self::assertGreaterThan(0, $p->height, $where);
                self::assertGreaterThanOrEqual(-self::EPS, $p->x, $where);
                self::assertGreaterThanOrEqual(-self::EPS, $p->y, $where);
                self::assertLessThanOrEqual($sw + self::EPS, $p->x + $p->width, "{$where}: exceeds sheet width");
                self::assertLessThanOrEqual($sh + self::EPS, $p->y + $p->height, "{$where}: exceeds sheet height");

                $asGiven  = isset($inputDims["{$p->width}x{$p->height}"]);
                $asTurned = isset($inputDims["{$p->height}x{$p->width}"]);
                if (!$mayRotate) {
                    self::assertFalse($p->rotated, "{$where}: rotated although the grain locks rotation");
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
                    $overlapX = min($a->x + $a->width,  $b->x + $b->width)  - max($a->x, $b->x);
                    $overlapY = min($a->y + $a->height, $b->y + $b->height) - max($a->y, $b->y);
                    $pair = "{$label}: pieces #{$i} and #{$j} on sheet {$sheet->index}";

                    self::assertFalse($overlapX > self::EPS && $overlapY > self::EPS, "{$pair} overlap");
                    // Layouts nested into one sheet (UpsizeMethod::MULTIPLE) may
                    // touch: the kerf of the dividing cut is ignored on exact multiples.
                    if ($kerf > 0 && self::partOf($sheet, $a) === self::partOf($sheet, $b)) {
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
     * @param array<string, mixed> $spec
     */
    private function assertConservation(NestingResult $result, array $spec, string $label): void
    {
        $free = $spec['grain'] === Grain::NONE;
        $key  = static function (float $w, float $h) use ($free): string {
            return $free ? min($w, $h) . 'x' . max($w, $h) : "{$w}x{$h}";
        };

        $expected = [];
        foreach ($spec['pieces'] as [$w, $h, $qty]) {
            $k = $key((float) $w, (float) $h);
            $expected[$k] = ($expected[$k] ?? 0) + $qty;
        }

        $actual = [];
        foreach ($result->sheets as $sheet) {
            foreach ($sheet->pieces as $p) {
                $k = $key($p->width, $p->height);
                $actual[$k] = ($actual[$k] ?? 0) + 1;
            }
        }
        foreach ($result->unplaced as $u) {
            self::assertGreaterThanOrEqual(1, $u->qty, $label);
            self::assertContains($u->reasonCode, [UnplacedPieces::REASON_STOCK, UnplacedPieces::REASON_TOO_LARGE], "{$label}: unknown reason code");
            $k = $key($u->width, $u->height);
            $actual[$k] = ($actual[$k] ?? 0) + $u->qty;
        }

        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual, "{$label}: pieces lost or invented");
        self::assertSame(array_sum(array_map(fn($u) => $u->qty, $result->unplaced)), $result->unplacedCount(), $label);
    }

    /** @param array<string, mixed> $spec */
    private function assertStock(NestingResult $result, array $spec, string $label): void
    {
        $stock = [];
        foreach ($spec['formats'] as [$w, $h, $count]) {
            $stock["{$w}x{$h}"] = ($stock["{$w}x{$h}"] ?? 0) + $count;
        }

        $used = [];
        foreach ($result->sheets as $sheet) {
            $k = "{$sheet->width}x{$sheet->height}";
            self::assertArrayHasKey($k, $stock, "{$label}: sheet uses unknown format {$k}");
            $used[$k] = ($used[$k] ?? 0) + 1;
        }
        foreach ($used as $k => $n) {
            self::assertLessThanOrEqual($stock[$k], $n, "{$label}: stock of {$k} exceeded");
        }

        $usage = [];
        foreach ($result->stockUsage as $u) {
            $usage["{$u->width}x{$u->height}"] = $u;
        }
        // stock usage order is unspecified – compare as sets
        $usedKeys  = array_keys($used);
        $usageKeys = array_keys($usage);
        sort($usedKeys);
        sort($usageKeys);
        self::assertSame($usedKeys, $usageKeys, "{$label}: stockUsage lists other formats than the sheets");
        foreach ($used as $k => $n) {
            self::assertSame($n, $usage[$k]->used, "{$label}: stockUsage.used of {$k}");
            self::assertSame($stock[$k] - $n, $usage[$k]->remaining, "{$label}: stockUsage.remaining of {$k}");
        }
    }

    private function assertTotals(NestingResult $result, string $label): void
    {
        $material  = 0.0;
        $pieceArea = 0.0;
        foreach ($result->sheets as $sheet) {
            $material += $sheet->width * $sheet->height / 1_000_000;
            foreach ($sheet->pieces as $p) {
                $pieceArea += $p->width * $p->height / 1_000_000;
            }
        }

        self::assertEqualsWithDelta(round($material, 4), $result->materialM2, 1e-9, "{$label}: materialM2");
        $util = $material > 0 ? round($pieceArea / $material * 100, 2) : 0.0;
        self::assertEqualsWithDelta($util, $result->utilizationPct, 1e-9, "{$label}: utilizationPct");
        self::assertGreaterThanOrEqual(0.0, $result->wastePctAvg, $label);
        self::assertLessThanOrEqual(100.0, $result->wastePctAvg, $label);
    }

    private function assertCuts(NestingResult $result, string $label): void
    {
        $count = 0;
        $mm    = 0.0;

        foreach ($result->sheets as $sheet) {
            $sw = $sheet->width;
            $sh = $sheet->height;
            $c  = $sheet->cuts;

            self::assertEqualsWithDelta($c->horizontalMm + $c->verticalMm, $c->totalMm, 0.051, "{$label}: cuts.totalMm");

            foreach ($c->horizontal as $cut) {
                $where = "{$label}: horizontal cut at y={$cut->position}";
                self::assertSame(Cut::HORIZONTAL, $cut->axis, $where);
                self::assertGreaterThan(0.0, $cut->position, $where);
                self::assertLessThan($sh, $cut->position, $where);
                self::assertGreaterThanOrEqual(0.0, $cut->start, $where);
                self::assertLessThan($cut->end, $cut->start, $where);
                self::assertLessThanOrEqual($sw + self::EPS, $cut->end, $where);
                self::assertEqualsWithDelta(round($cut->end - $cut->start, 1), $cut->length, 1e-9, $where);
            }
            foreach ($c->vertical as $cut) {
                $where = "{$label}: vertical cut at x={$cut->position}";
                self::assertSame(Cut::VERTICAL, $cut->axis, $where);
                self::assertGreaterThan(0.0, $cut->position, $where);
                self::assertLessThan($sw, $cut->position, $where);
                self::assertGreaterThanOrEqual(0.0, $cut->start, $where);
                self::assertLessThan($cut->end, $cut->start, $where);
                self::assertLessThanOrEqual($sh + self::EPS, $cut->end, $where);
                self::assertEqualsWithDelta(round($cut->end - $cut->start, 1), $cut->length, 1e-9, $where);
            }

            $count += $c->count();
            $mm    += $c->horizontalMm + $c->verticalMm;
        }

        self::assertSame($count, $result->cutCount, "{$label}: cutCount");
        self::assertEqualsWithDelta(round($mm, 1), $result->cutsMm, 0.051, "{$label}: cutsMm");
    }

    /**
     * Seal length = per piece the two edges perpendicular to the channels.
     *
     * @param array<string, mixed> $spec
     */
    private function assertSeal(NestingResult $result, array $spec, string $label): void
    {
        if ($spec['grain'] === Grain::NONE) {
            self::assertNull($result->sealMm, $label);
            foreach ($result->sheets as $sheet) {
                self::assertNull($sheet->sealMm, $label);
            }
            return;
        }

        $total = 0.0;
        foreach ($result->sheets as $sheet) {
            $mm = 0.0;
            foreach ($sheet->pieces as $p) {
                $mm += 2 * ($spec['grain'] === Grain::ALONG_HEIGHT ? $p->width : $p->height);
            }
            self::assertEqualsWithDelta(round($mm, 1), $sheet->sealMm, 1e-9, "{$label}: sheet sealMm");
            $total += $sheet->sealMm;
        }
        self::assertEqualsWithDelta(round($total, 1), $result->sealMm, 1e-9, "{$label}: sealMm");
    }

    /** @param array<string, mixed> $spec */
    private function assertRemnants(NestingResult $result, array $spec, string $label): void
    {
        /** @var Remnants $option */
        $option = $spec['remnants'];

        if (!$option->enabled()) {
            self::assertNull($result->remnantM2, $label);
            self::assertNull($result->remnantPct, $label);
            self::assertNull($result->netMaterialM2, $label);
            foreach ($result->sheets as $sheet) {
                self::assertNull($sheet->remnants, $label);
                self::assertNull($sheet->remnantM2, $label);
            }
            return;
        }

        $allowed = [];
        foreach ($spec['remnantFormats'] as [$w, $h]) {
            $allowed["{$w}x{$h}"] = true;
            if ($spec['grain'] === Grain::NONE) {
                $allowed["{$h}x{$w}"] = true;
            }
        }
        $kerf  = $spec['kerf'];
        $total = 0.0;

        foreach ($result->sheets as $sheet) {
            self::assertIsArray($sheet->remnants, $label);
            $sw   = $sheet->width;
            $sh   = $sheet->height;
            $rs   = $sheet->remnants;
            $area = 0.0;

            self::assertLessThanOrEqual(10, count($rs), "{$label}: more than 10 remnants on one sheet");

            foreach ($rs as $i => $r) {
                $where = "{$label}: remnant {$r->width}x{$r->height} at ({$r->x},{$r->y}) on sheet {$sheet->index}";
                self::assertGreaterThanOrEqual(-self::EPS, $r->x, $where);
                self::assertGreaterThanOrEqual(-self::EPS, $r->y, $where);
                self::assertLessThanOrEqual($sw + self::EPS, $r->x + $r->width, $where);
                self::assertLessThanOrEqual($sh + self::EPS, $r->y + $r->height, $where);

                if ($option->mode === Remnants::AUTO) {
                    $fits = ($r->width >= $option->minWidth - self::EPS && $r->height >= $option->minHeight - self::EPS)
                        || ($spec['grain'] === Grain::NONE
                            && $r->width >= $option->minHeight - self::EPS && $r->height >= $option->minWidth - self::EPS);
                    self::assertTrue($fits, "{$where}: below the minimum {$option->minWidth}x{$option->minHeight}");
                } else {
                    self::assertArrayHasKey("{$r->width}x{$r->height}", $allowed, "{$where}: not a registered remnant format");
                }

                // must lie in free space: no intersection with kerf-inflated piece footprints
                foreach ($sheet->pieces as $p) {
                    $fw = min($p->width  + $kerf, $sw - $p->x);
                    $fh = min($p->height + $kerf, $sh - $p->y);
                    self::assertFalse(
                        self::intersects($r->x, $r->y, $r->width, $r->height, $p->x, $p->y, $fw, $fh),
                        "{$where}: overlaps piece {$p->width}x{$p->height} at ({$p->x},{$p->y})"
                    );
                }
                foreach ($rs as $j => $o) {
                    if ($j > $i) {
                        self::assertFalse(
                            self::intersects($r->x, $r->y, $r->width, $r->height, $o->x, $o->y, $o->width, $o->height),
                            "{$where}: overlaps another remnant"
                        );
                    }
                }
                $area += $r->areaM2();
            }

            self::assertEqualsWithDelta(round($area, 4), $sheet->remnantM2, 1e-9, "{$label}: sheet remnantM2");
            $total += $area;
        }

        self::assertEqualsWithDelta(round($total, 4), $result->remnantM2, 1e-9, "{$label}: remnantM2");
        $pct = $result->materialM2 > 0 ? round($total / $result->materialM2 * 100, 2) : 0.0;
        self::assertEqualsWithDelta($pct, $result->remnantPct, 0.011, "{$label}: remnantPct");
        self::assertEqualsWithDelta(round($result->materialM2 - $total, 4), $result->netMaterialM2, 1e-9, "{$label}: netMaterialM2");
    }

    /**
     * Options\Upsize: an enlarged sheet records a smaller registered format
     * and a method of the option; lastSheet() enlarges at most one sheet,
     * none() none. UpsizeMethod::REMNANT: the original format still holds all
     * pieces. UpsizeMethod::MULTIPLE: the format holds the original one in the
     * grid the method allows (per-direction limit and tolerance), the nested
     * sheets lie apart inside it and every piece lies in one of them. Whether
     * an enlargement bought a remnant that meets the threshold is pinned by
     * FeaturesTest – the marked remnants follow an independent threshold.
     * (Stock limits and geometry are covered by the other checks.)
     *
     * @param array<string, mixed> $spec
     */
    private function assertUpsize(NestingResult $result, array $spec, string $label): void
    {
        /** @var Upsize $option */
        $option  = $spec['upsize'];
        $formats = [];
        foreach ($spec['formats'] as [$w, $h]) {
            $formats["{$w}x{$h}"] = true;
        }

        $enlarged = 0;
        foreach ($result->sheets as $sheet) {
            $from = $sheet->upsizedFrom;
            if ($from === null) {
                self::assertSame([null, null, null], [$sheet->upsizeMethod, $sheet->nestedSheets, $sheet->nestedCapacity], "{$label}: upsize fields on a sheet that was not enlarged");
                continue;
            }
            $enlarged++;
            $where = "{$label}: sheet {$sheet->index} enlarged from {$from['width']}x{$from['height']}";
            self::assertTrue($option->enabled(), "{$where} although upsize is off");
            if ($option->scope === Upsize::LAST_SHEET) {
                self::assertSame(1, $enlarged, "{$label}: more than one sheet enlarged with lastSheet()");
            }
            self::assertArrayHasKey("{$from['width']}x{$from['height']}", $formats, "{$where}: not a registered format");
            self::assertLessThan($sheet->width * $sheet->height, $from['width'] * $from['height'], "{$where}: not larger");
            $method = $option->method((string) $sheet->upsizeMethod);
            self::assertNotNull($method, "{$where}: by '{$sheet->upsizeMethod}', not a method of the option");

            if ($method->type === UpsizeMethod::REMNANT) {
                self::assertSame([null, null], [$sheet->nestedSheets, $sheet->nestedCapacity], $where);
                foreach ($sheet->pieces as $p) {
                    self::assertLessThanOrEqual($from['width'] + self::EPS, $p->x + $p->width, "{$where}: piece did not fit the original format");
                    self::assertLessThanOrEqual($from['height'] + self::EPS, $p->y + $p->height, "{$where}: piece did not fit the original format");
                }
                continue;
            }

            $columns = min($method->maxWidthMultiple, (int) floor(($sheet->width + self::EPS) / $from['width']));
            $rows    = min($method->maxHeightMultiple, (int) floor(($sheet->height + self::EPS) / $from['height']));
            self::assertSame($columns * $rows, $sheet->nestedCapacity, "{$where}: capacity");
            self::assertGreaterThanOrEqual(2, $sheet->nestedCapacity, "{$where}: fewer than two parts");
            self::assertLessThanOrEqual($method->widthTolerance + self::EPS, $sheet->width - $columns * $from['width'], "{$where}: leftover along the width");
            self::assertLessThanOrEqual($method->heightTolerance + self::EPS, $sheet->height - $rows * $from['height'], "{$where}: leftover along the height");

            $nested = $sheet->nestedSheets;
            self::assertIsArray($nested, $where);
            self::assertGreaterThanOrEqual(1, count($nested), $where);
            self::assertLessThanOrEqual($sheet->nestedCapacity, count($nested), "{$where}: more nested sheets than parts");
            foreach ($nested as $i => $r) {
                self::assertSame([$from['width'], $from['height']], [$r['width'], $r['height']], "{$where}: nested sheet #{$i} not of the original format");
                self::assertGreaterThanOrEqual(-self::EPS, min($r['x'], $r['y']), "{$where}: nested sheet #{$i} outside");
                self::assertLessThanOrEqual($sheet->width + self::EPS, $r['x'] + $r['width'], "{$where}: nested sheet #{$i} outside");
                self::assertLessThanOrEqual($sheet->height + self::EPS, $r['y'] + $r['height'], "{$where}: nested sheet #{$i} outside");
                foreach ($nested as $j => $o) {
                    if ($j > $i) {
                        self::assertFalse(
                            self::intersects($r['x'], $r['y'], $r['width'], $r['height'], $o['x'], $o['y'], $o['width'], $o['height']),
                            "{$where}: nested sheets #{$i} and #{$j} overlap"
                        );
                    }
                }
            }
            foreach ($sheet->pieces as $p) {
                self::assertGreaterThanOrEqual(0, self::partOf($sheet, $p), "{$where}: piece at ({$p->x},{$p->y}) outside every nested sheet");
            }
        }
    }

    /** Index of the nested sheet (UpsizeMethod::MULTIPLE) that holds the piece; -1 on a sheet without. */
    private static function partOf(SheetPlan $sheet, Placement $p): int
    {
        foreach ($sheet->nestedSheets ?? [] as $i => $r) {
            if ($p->x >= $r['x'] - self::EPS && $p->y >= $r['y'] - self::EPS
                && $p->x + $p->width <= $r['x'] + $r['width'] + self::EPS
                && $p->y + $p->height <= $r['y'] + $r['height'] + self::EPS) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * Pass 0 of every multi-pass search is the single FFD pass, and the best
     * candidate is chosen by (fewest unplaced, lowest primary cost) – so the
     * multi-pass result can never be worse than the single pass on that key.
     * With Remnants::$upsize the comparison happens before the sheets are
     * enlarged, so only the unplaced count is comparable afterwards.
     */
    private function assertNotWorseThanSinglePass(NestingResult $result, NestingResult $baseline, Objective $objective, bool $upsize, string $label): void
    {
        self::assertLessThanOrEqual($baseline->unplacedCount(), $result->unplacedCount(), "{$label}: more unplaced than the single pass");
        if ($result->unplacedCount() !== $baseline->unplacedCount() || $upsize) {
            return;
        }

        $cost = static fn(NestingResult $r): float => $objective->name === Objective::MIN_CUTS
            ? $r->materialM2 + $objective->cutCostM2 * $r->cutCount
            : $r->materialM2;
        self::assertLessThanOrEqual($cost($baseline) + 1e-9, $cost($result), "{$label}: primary cost worse than the single pass");
    }

    private static function intersects(float $ax, float $ay, float $aw, float $ah, float $bx, float $by, float $bw, float $bh): bool
    {
        return $ax < $bx + $bw - self::EPS && $ax + $aw > $bx + self::EPS
            && $ay < $by + $bh - self::EPS && $ay + $ah > $by + self::EPS;
    }
}
