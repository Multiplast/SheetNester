<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Finds usable remnants (largest empty rectangles or registered remnant
 * formats) in the free space of a nested sheet.
 *
 * ADVISORY: marked remnants show the operator / backoffice which leftover
 * rectangles are worth keeping. They do NOT guarantee that the remnant can
 * be separated from the surrounding waste with one sequence of guillotine
 * cuts. Deterministic – no RNG involved, seeded runs stay reproducible.
 *
 * @internal
 */
final class RemnantFinder
{
    /** Safety cap on marked remnants per sheet. */
    private const MAX_REMNANTS_PER_SHEET = 10;

    /**
     * Deterministic complexity cap for a whole remnant pass, in grid-cell
     * tests (~1 s CPU). Deliberately NOT wall-clock: seeded runs must stay
     * byte-reproducible, and the remnant phase runs outside timeBudgetMs.
     * On pathological inputs (hundreds of footprints per sheet) marking just
     * stops early – the feature is advisory.
     */
    public const WORK_BUDGET = 30_000_000;

    /**
     * @param array<int, array{float, float}> $remnantFormats Formats for the "defined" mode.
     */
    public function __construct(
        private float $kerf,
        private bool  $allowRotation,
        private float $minRemnantSide,
        private array $remnantFormats,
    ) {
    }

    /**
     * AUTO mode: repeatedly finds the largest empty rectangle on the sheet and
     * marks it as a remnant while its shorter side is at least minRemnantSide.
     * Occupancy = piece footprints + already marked remnants (both inflated by
     * kerf to the right/bottom – mirrors the engine's placement convention).
     *
     * @return FreeRect[]
     */
    public function findAuto(Sheet $sheet, int &$workBudget): array
    {
        $remnants = [];

        while (count($remnants) < self::MAX_REMNANTS_PER_SHEET && $workBudget > 0) {
            $occupied = $this->collectOccupiedRects($sheet, $remnants);
            $rect     = $this->largestEmptyRect(
                $occupied,
                $sheet->format->width,
                $sheet->format->height,
                $workBudget
            );

            if ($rect === null || min($rect->width, $rect->height) < $this->minRemnantSide) {
                break;
            }
            $remnants[] = $rect;
        }

        return $remnants;
    }

    /**
     * DEFINED mode: greedily places registered remnant formats (largest area
     * first, each repeatedly while it fits). Candidate anchors are the
     * compressed footprint-edge coordinates; the lexicographically smallest
     * (y, x) position wins (top-left heuristic). Rotation is tried only when
     * rotation is allowed, unrotated orientation first.
     *
     * @return FreeRect[]
     */
    public function findDefined(Sheet $sheet, int &$workBudget): array
    {
        if (empty($this->remnantFormats)) {
            return [];
        }

        $sw = $sheet->format->width;
        $sh = $sheet->format->height;

        $formats = $this->remnantFormats;
        usort($formats, fn($a, $b) => ($b[0] * $b[1]) <=> ($a[0] * $a[1]));

        $remnants = [];

        foreach ($formats as [$fw, $fh]) {
            $orientations = [[$fw, $fh]];
            if ($this->allowRotation && $fw !== $fh) {
                $orientations[] = [$fh, $fw];
            }

            while (count($remnants) < self::MAX_REMNANTS_PER_SHEET && $workBudget > 0) {
                $occupied = $this->collectOccupiedRects($sheet, $remnants);

                $xs = [0.0];
                $ys = [0.0];
                foreach ($occupied as [$ox, $oy, $ow, $oh]) {
                    $xs[] = $ox;
                    $xs[] = $ox + $ow;
                    $ys[] = $oy;
                    $ys[] = $oy + $oh;
                }
                $xs = $this->uniqueSorted($xs);
                $ys = $this->uniqueSorted($ys);

                $workBudget -= count($xs) * count($ys) * max(1, count($occupied));

                $best = null;   // [x, y, w, h]
                foreach ($ys as $y) {
                    foreach ($xs as $x) {
                        foreach ($orientations as [$w, $h]) {
                            if ($this->rectIsFree($x, $y, $w, $h, $occupied, $sw, $sh)) {
                                $best = [$x, $y, $w, $h];
                                break 3;   // first fit in (y, x) order wins
                            }
                        }
                    }
                }

                if ($best === null) {
                    break;   // this format no longer fits anywhere
                }
                $remnants[] = new FreeRect($best[0], $best[1], $best[2], $best[3]);
            }
        }

        return $remnants;
    }

    /**
     * Area (m²) of the largest single empty rectangle across all sheets.
     * Cheap single-shot variant for the best_remnant tie-break: one
     * largestEmptyRect call per sheet, occupied = piece footprints only.
     * Deterministic; WORK_BUDGET guards pathological sheets.
     *
     * @param Sheet[] $sheets
     */
    public function largestSingleRemnantM2(array $sheets): float
    {
        $best   = 0.0;
        $budget = self::WORK_BUDGET;

        foreach ($sheets as $sheet) {
            $occupied = $this->collectOccupiedRects($sheet, []);
            $rect     = $this->largestEmptyRect(
                $occupied,
                $sheet->format->width,
                $sheet->format->height,
                $budget
            );
            if ($rect !== null) {
                $best = max($best, $rect->area() / 1_000_000);
            }
            if ($budget <= 0) {
                break;
            }
        }

        return $best;
    }

    /**
     * Footprints (dimension + kerf to the right/bottom, clipped to the sheet)
     * of placed pieces and already marked remnants.
     *
     * @param  FreeRect[] $remnants
     * @return array<int, array{float, float, float, float}>  Each [x, y, w, h].
     */
    private function collectOccupiedRects(Sheet $sheet, array $remnants): array
    {
        $sw = $sheet->format->width;
        $sh = $sheet->format->height;

        $rects = [];
        foreach ($sheet->pieces as $p) {
            $rects[] = [
                $p->x,
                $p->y,
                min($p->width  + $this->kerf, $sw - $p->x),
                min($p->height + $this->kerf, $sh - $p->y),
            ];
        }
        foreach ($remnants as $r) {
            $rects[] = [
                $r->x,
                $r->y,
                min($r->width  + $this->kerf, $sw - $r->x),
                min($r->height + $this->kerf, $sh - $r->y),
            ];
        }
        return $rects;
    }

    /**
     * Finds the largest axis-aligned empty rectangle on a sw × sh sheet given
     * occupied rectangles. Coordinate compression over occupied edges +
     * "largest rectangle in histogram" per grid row (widths/heights in mm).
     * Deterministic tie-break: larger area, then smaller y, then smaller x.
     *
     * Decrements $workBudget by the number of performed grid-cell tests so the
     * caller can enforce the deterministic WORK_BUDGET cap.
     *
     * @param array<int, array{float, float, float, float}> $occupied
     */
    private function largestEmptyRect(array $occupied, float $sw, float $sh, int &$workBudget): ?FreeRect
    {
        if ($sw <= 0 || $sh <= 0) {
            return null;
        }

        $xs = [0.0, $sw];
        $ys = [0.0, $sh];
        foreach ($occupied as [$ox, $oy, $ow, $oh]) {
            foreach ([$ox, $ox + $ow] as $x) {
                if ($x > 0 && $x < $sw) {
                    $xs[] = $x;
                }
            }
            foreach ([$oy, $oy + $oh] as $y) {
                if ($y > 0 && $y < $sh) {
                    $ys[] = $y;
                }
            }
        }
        $xs = $this->uniqueSorted($xs);
        $ys = $this->uniqueSorted($ys);

        $cols = count($xs) - 1;
        $rows = count($ys) - 1;
        if ($cols < 1 || $rows < 1) {
            return null;
        }

        $workBudget -= $rows * $cols * max(1, count($occupied));

        // Occupancy of compressed cells (cell-centre containment test).
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $cy = ($ys[$r] + $ys[$r + 1]) / 2;
            for ($c = 0; $c < $cols; $c++) {
                $cx   = ($xs[$c] + $xs[$c + 1]) / 2;
                $free = true;
                foreach ($occupied as [$ox, $oy, $ow, $oh]) {
                    if ($cx > $ox && $cx < $ox + $ow && $cy > $oy && $cy < $oy + $oh) {
                        $free = false;
                        break;
                    }
                }
                $grid[$r][$c] = $free;
            }
        }

        $best    = null;   // [area, y, x, width, height]
        $heights = array_fill(0, $cols, 0.0);   // free run (mm) ending at current row

        for ($r = 0; $r < $rows; $r++) {
            $rowH = $ys[$r + 1] - $ys[$r];
            for ($c = 0; $c < $cols; $c++) {
                $heights[$c] = $grid[$r][$c] ? $heights[$c] + $rowH : 0.0;
            }

            // Largest rectangle in histogram (stack of column indices with
            // increasing heights); widths are real mm distances between
            // compressed x coordinates. Rect bottom = $ys[$r + 1].
            $stack = [];
            for ($c = 0; $c <= $cols; $c++) {
                $h = $c < $cols ? $heights[$c] : 0.0;
                while (!empty($stack) && $heights[end($stack)] > $h + 1e-9) {
                    $topC   = array_pop($stack);
                    $height = $heights[$topC];
                    $left   = empty($stack) ? 0 : end($stack) + 1;
                    $width  = $xs[$c] - $xs[$left];
                    $area   = $width * $height;
                    if ($area > 1e-9) {
                        $y = $ys[$r + 1] - $height;
                        $x = $xs[$left];
                        if ($best === null
                            || $area > $best[0] + 1e-9
                            || (abs($area - $best[0]) <= 1e-9
                                && ($y < $best[1] - 1e-9
                                    || (abs($y - $best[1]) <= 1e-9 && $x < $best[2] - 1e-9)))
                        ) {
                            $best = [$area, $y, $x, $width, $height];
                        }
                    }
                }
                $stack[] = $c;
            }
        }

        return $best === null
            ? null
            : new FreeRect($best[2], $best[1], $best[3], $best[4]);
    }

    /**
     * Whether the axis-aligned rectangle lies fully on the sheet and does not
     * intersect any occupied rectangle.
     *
     * @param array<int, array{float, float, float, float}> $occupied
     */
    private function rectIsFree(
        float $x,
        float $y,
        float $w,
        float $h,
        array $occupied,
        float $sw,
        float $sh
    ): bool {
        if ($x < -1e-9 || $y < -1e-9 || $x + $w > $sw + 1e-9 || $y + $h > $sh + 1e-9) {
            return false;
        }
        foreach ($occupied as [$ox, $oy, $ow, $oh]) {
            if ($x < $ox + $ow - 1e-9 && $x + $w > $ox + 1e-9
                && $y < $oy + $oh - 1e-9 && $y + $h > $oy + 1e-9) {
                return false;
            }
        }
        return true;
    }

    /**
     * Sorts float coordinates ascending and drops near-duplicates (1e-6).
     *
     * @param  float[] $values
     * @return float[]
     */
    private function uniqueSorted(array $values): array
    {
        sort($values);
        $out = [];
        foreach ($values as $v) {
            if (empty($out) || $v - end($out) > 1e-6) {
                $out[] = $v;
            }
        }
        return $out;
    }
}
