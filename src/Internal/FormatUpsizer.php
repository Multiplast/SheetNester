<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Upsize;

/**
 * Post-processing step for Options\Upsize: gives a sheet a larger stock
 * format when that turns its waste into a usable remnant – the mirror image
 * of FormatReducer. Runs once, on the winning plan, after the solver and
 * before the sheets are ordered for printing.
 *
 * Which sheets are considered is the scope:
 *  - Upsize::LAST_SHEET: only the last sheet of the plan – the one opened
 *    last (the last element of $sheets, which arrive in opening order);
 *  - Upsize::ALL_SHEETS: every sheet, in opening order.
 *
 * A sheet's candidate formats are the available stock formats (remaining
 * stock > 0) with a larger area that still contain all its pieces at their
 * current positions. Each candidate is valued by its net material:
 *
 *     net = format area − area of the remnants that meet the upsize
 *           threshold (found by the RemnantFinder given here)
 *
 * The sheet moves to the candidate with the lowest net material, but only on
 * a strict improvement over its current format; ties go to the smaller
 * format. Hence a fully used sheet never grows (a larger format adds at most
 * as much remnant as area), and a sheet grows only when its waste becomes
 * part of a remnant that meets the threshold. Pieces never move.
 *
 * Deterministic: no RNG, a fixed work budget for the remnant searches.
 *
 * @internal
 */
final class FormatUpsizer
{
    /**
     * @param StockFormat[] $stockFormats Registered formats; never mutated.
     * @param RemnantFinder $finder       Configured with the upsize threshold.
     * @param string        $remnantMode  Remnants::AUTO (minimum size) or Remnants::DEFINED (formats).
     * @param string        $scope        Upsize::LAST_SHEET or Upsize::ALL_SHEETS.
     */
    public function __construct(
        private array         $stockFormats,
        private float         $kerf,
        private RemnantFinder $finder,
        private string        $remnantMode,
        private string        $scope,
    ) {
    }

    /**
     * @param  Sheet[] $sheets Modified in place: Sheet::$format and
     *                         Sheet::$upsizedFrom of the enlarged sheets.
     * @return int             Number of sheets reassigned.
     */
    public function upsize(array &$sheets): int
    {
        if ($this->remnantMode === Remnants::NONE || $this->scope === Upsize::NONE || $sheets === []) {
            return 0;
        }

        // Live inventory: registered stock minus the sheets of this plan.
        $inventory = [];
        foreach ($this->stockFormats as $fmt) {
            $key = $fmt->width . ':' . $fmt->height;
            $inventory[$key] = ($inventory[$key] ?? 0) + ($fmt->stock ?? PHP_INT_MAX);
        }
        foreach ($sheets as $sheet) {
            $inventory[$sheet->format->width . ':' . $sheet->format->height]--;
        }

        // Candidates in ascending area (stable, so equal areas keep the
        // registration order) – the first best net wins = smallest format.
        $formats = $this->stockFormats;
        usort($formats, fn(StockFormat $a, StockFormat $b) => $a->area() <=> $b->area());

        $targets = $this->scope === Upsize::ALL_SHEETS ? $sheets : [$sheets[array_key_last($sheets)]];
        $budget  = RemnantFinder::WORK_BUDGET;
        $changed = 0;

        foreach ($targets as $sheet) {
            if ($budget <= 0) {
                break;
            }
            if ($this->upsizeSheet($sheet, $formats, $inventory, $budget)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Moves one sheet to the larger format with the lowest net material, if
     * that is a strict improvement. Updates the inventory on success.
     *
     * @param StockFormat[]      $formats   Registered formats, ascending area.
     * @param array<string, int> $inventory Remaining stock by "width:height".
     */
    private function upsizeSheet(Sheet $sheet, array $formats, array &$inventory, int &$budget): bool
    {
        $current   = $sheet->format;
        $maxX      = 0.0;
        $maxY      = 0.0;
        $footprint = 0.0;
        foreach ($sheet->pieces as $p) {
            $maxX       = max($maxX, $p->x + $p->width);
            $maxY       = max($maxY, $p->y + $p->height);
            $footprint += min($p->width + $this->kerf, $current->width - $p->x)
                        * min($p->height + $this->kerf, $current->height - $p->y);
        }

        $currentCredit = $this->remnantArea($sheet, $current, $budget);

        // A larger format can improve the net material by at most the waste
        // the sheet has now (free area that is not a remnant yet).
        if ($current->area() - $footprint - $currentCredit <= 1e-6) {
            return false;
        }

        $bestNet    = $current->area() - $currentCredit;
        $bestFormat = null;

        foreach ($formats as $candidate) {
            if ($budget <= 0) {
                break;
            }
            if ($candidate->area() <= $current->area() + 1e-9) {
                continue;
            }
            if ($maxX > $candidate->width + 1e-9 || $maxY > $candidate->height + 1e-9) {
                continue;   // the pieces would not fit at their positions
            }
            if (($inventory[$candidate->width . ':' . $candidate->height] ?? 0) <= 0) {
                continue;   // no stock left
            }

            $net = $candidate->area() - $this->remnantArea($sheet, $candidate, $budget);
            if ($net < $bestNet - 1e-9) {
                $bestNet    = $net;
                $bestFormat = $candidate;
            }
        }

        if ($bestFormat === null) {
            return false;
        }

        $inventory[$current->width . ':' . $current->height]++;
        $inventory[$bestFormat->width . ':' . $bestFormat->height]--;

        $sheet->upsizedFrom = $current;
        $sheet->format      = new StockFormat($bestFormat->width, $bestFormat->height, $bestFormat->stock);
        return true;
    }

    /**
     * Total area (mm²) of the remnants meeting the upsize threshold that the
     * finder marks on the sheet's pieces laid on the given format.
     */
    private function remnantArea(Sheet $sheet, StockFormat $format, int &$budget): float
    {
        $probe         = new Sheet($sheet->index, $format);
        $probe->pieces = $sheet->pieces;

        $found = $this->remnantMode === Remnants::AUTO
            ? $this->finder->findAuto($probe, $budget)
            : $this->finder->findDefined($probe, $budget);

        $area = 0.0;
        foreach ($found as $r) {
            $area += $r->area();
        }
        return $area;
    }
}
