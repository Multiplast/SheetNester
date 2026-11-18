<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\Options\Upsize;
use Multiplast\SheetNester\Options\UpsizeMethod;

/**
 * Finishing step for Options\Upsize: gives sheets of the winning plan a
 * larger stock format. Runs once, after the solver and before the sheets are
 * ordered for printing. A piece never moves on its layout.
 *
 * Scope: Upsize::LAST_SHEET – only the last sheet of the plan (the last
 * element of $sheets, which arrive in opening order); Upsize::ALL_SHEETS –
 * every sheet. The option's methods are tried in their order; a sheet that
 * one method changed (or nested into a parent) is left alone by the later
 * ones.
 *
 * UpsizeMethod::REMNANT – the mirror image of FormatReducer. A sheet's
 * candidates are the available stock formats (remaining stock > 0) with a
 * larger area that still contain all its pieces at their current positions,
 * valued by their net material:
 *
 *     net = format area − area of the remnants that meet the method's
 *           threshold (found by the RemnantFinder given here)
 *
 * The sheet moves to the candidate with the lowest net material, but only on
 * a strict improvement over its current format; ties go to the smaller
 * format. Hence a fully used sheet never grows (a larger format adds at most
 * as much remnant as area), and a sheet grows only when its waste becomes
 * part of a remnant that meets the threshold.
 *
 * UpsizeMethod::MULTIPLE – sheets are cut out of an available stock format
 * (the parent) that holds their format in a grid: columns = how many fit
 * along the width, at most maxWidthMultiple; rows likewise along the height;
 * at least two parts in all, and the leftover beyond the grid within
 * widthTolerance / heightTolerance. The layouts go into the parts row by row
 * (along the width first), in opening order, from the top-left corner, so
 * the leftover is one strip along the right / bottom edge. Neighbouring parts
 * are a kerf apart where the leftover leaves room for it, else (exact
 * multiples) the kerf between them is ignored.
 *
 * With ALL_SHEETS the sheets in scope are grouped by format and each group's
 * parents are chosen to nest as many sheets as possible, then to leave the
 * fewest parts empty, then to use the fewest parents. With LAST_SHEET one
 * parent takes the last sheet; its further parts take other sheets of the
 * same format, the parent chosen to leave the fewest parts empty, then to
 * nest the most sheets. Among parents with the same number of parts the one
 * with the least leftover goes first, then the smaller one. An empty part
 * stays as a remnant.
 *
 * Deterministic: no RNG, a fixed work budget for the remnant searches.
 *
 * @internal
 */
final class FormatUpsizer
{
    private const EPS = 1e-6;

    private ?UpsizeMethod $remnant;

    /**
     * @param StockFormat[]      $stockFormats Registered formats; never mutated.
     * @param Upsize             $option       Scope and methods.
     * @param RemnantFinder|null $finder       Configured with the remnant method's
     *                                         threshold; required with that method.
     */
    public function __construct(
        private array          $stockFormats,
        private float          $kerf,
        private Upsize         $option,
        private ?RemnantFinder $finder = null,
    ) {
        $this->remnant = $option->method(UpsizeMethod::REMNANT);
        if ($finder === null && $this->remnant !== null) {
            throw new \LogicException('UpsizeMethod::REMNANT needs a RemnantFinder.');
        }
    }

    /**
     * @param  Sheet[] $sheets In opening order. Modified in place: the format of
     *                         enlarged sheets; sheets nested into a parent are
     *                         replaced by it (at the position of the first of
     *                         them) and the indices renumbered.
     * @return int             Number of sheets changed (a parent counts once).
     */
    public function upsize(array &$sheets): int
    {
        if (!$this->option->enabled() || $sheets === []) {
            return 0;
        }

        // Live inventory: registered stock minus the sheets of this plan.
        $inventory = [];
        foreach ($this->stockFormats as $fmt) {
            $key = self::key($fmt);
            $inventory[$key] = ($inventory[$key] ?? 0) + ($fmt->stock ?? PHP_INT_MAX);
        }
        foreach ($sheets as $sheet) {
            $inventory[self::key($sheet->format)]--;
        }

        // Candidates in ascending area (stable, so equal areas keep the
        // registration order) – the first best wins = smallest format.
        $formats = $this->stockFormats;
        usort($formats, fn(StockFormat $a, StockFormat $b) => $a->area() <=> $b->area());

        $last    = $sheets[array_key_last($sheets)];
        $done    = [];   // spl_object_id => true: changed or nested by an earlier method
        $budget  = RemnantFinder::WORK_BUDGET;
        $changed = 0;

        foreach ($this->option->methods as $method) {
            if ($this->option->scope === Upsize::ALL_SHEETS) {
                $targets = array_values(array_filter($sheets, fn(Sheet $s) => !isset($done[spl_object_id($s)])));
            } else {
                $targets = isset($done[spl_object_id($last)]) ? [] : [$last];
            }
            if ($targets === []) {
                break;
            }

            if ($method->type === UpsizeMethod::REMNANT) {
                foreach ($targets as $sheet) {
                    if ($budget <= 0) {
                        break;
                    }
                    if ($this->upsizeSheet($sheet, $formats, $inventory, $budget)) {
                        $done[spl_object_id($sheet)] = true;
                        $changed++;
                    }
                }
            } else {
                $changed += $this->nestInMultiples($sheets, $targets, $formats, $inventory, $done, $method);
            }
        }

        if ($changed > 0) {
            foreach ($sheets as $i => $sheet) {
                $sheet->index = $i;
            }
        }
        return $changed;
    }

    // -------------------------------------------------------------------------
    // UpsizeMethod::REMNANT
    // -------------------------------------------------------------------------

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
        if ($current->area() - $footprint - $currentCredit <= self::EPS) {
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
            if (($inventory[self::key($candidate)] ?? 0) <= 0) {
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

        $inventory[self::key($current)]++;
        $inventory[self::key($bestFormat)]--;

        $sheet->upsizedFrom  = $current;
        $sheet->upsizeMethod = UpsizeMethod::REMNANT;
        $sheet->format       = new StockFormat($bestFormat->width, $bestFormat->height, $bestFormat->stock);
        return true;
    }

    /**
     * Total area (mm²) of the remnants meeting the remnant method's threshold
     * that the finder marks on the sheet's pieces laid on the given format.
     */
    private function remnantArea(Sheet $sheet, StockFormat $format, int &$budget): float
    {
        $probe         = new Sheet($sheet->index, $format);
        $probe->pieces = $sheet->pieces;

        $found = $this->remnant->usesFormats()
            ? $this->finder->findDefined($probe, $budget)
            : $this->finder->findAuto($probe, $budget);

        $area = 0.0;
        foreach ($found as $r) {
            $area += $r->area();
        }
        return $area;
    }

    // -------------------------------------------------------------------------
    // UpsizeMethod::MULTIPLE
    // -------------------------------------------------------------------------

    /**
     * Nests the target sheets into parents that hold their format several
     * times (see the class doc).
     *
     * @param Sheet[]            $sheets    The plan, opening order; nested sheets are
     *                                      replaced by their parent.
     * @param Sheet[]            $targets   Sheets in scope no earlier method changed.
     * @param StockFormat[]      $formats   Registered formats, ascending area.
     * @param array<string, int> $inventory Remaining stock by "width:height".
     * @param array<int, bool>   $done      spl_object_id of changed / nested sheets.
     * @return int                          Number of parents created.
     */
    private function nestInMultiples(
        array        &$sheets,
        array        $targets,
        array        $formats,
        array        &$inventory,
        array        &$done,
        UpsizeMethod $method,
    ): int {
        $lastOnly = $this->option->scope === Upsize::LAST_SHEET;

        // Same-format groups, in order of first appearance among the targets.
        $groups = [];
        foreach ($targets as $sheet) {
            $groups[self::key($sheet->format)][] = $sheet;
        }

        $created = 0;
        foreach ($groups as $key => $members) {
            $child   = $members[0]->format;
            $options = $this->parents($child, $formats, $inventory, $method);
            if ($options === []) {
                continue;
            }

            if ($lastOnly) {
                // The last sheet, then the other sheets of its format (opening
                // order) as candidates for the further parts of its parent.
                foreach ($sheets as $s) {
                    if ($s !== $members[0] && !isset($done[spl_object_id($s)]) && self::key($s->format) === $key) {
                        $members[] = $s;
                    }
                }
                $plan = $this->planOneParent(count($members), $options);
            } else {
                $plan = $this->planParents(count($members), $options, $inventory);
            }

            $offset = 0;
            foreach ($plan as $parent) {
                $children = array_slice($members, $offset, $parent['parts']);
                $offset  += count($children);

                // Parts follow the opening order of the sheets.
                $position = [];
                foreach ($children as $c) {
                    $position[spl_object_id($c)] = array_search($c, $sheets, true);
                }
                usort($children, fn(Sheet $a, Sheet $b) => $position[spl_object_id($a)] <=> $position[spl_object_id($b)]);

                $assembled = $this->assemble($parent, $child, $children);

                $first          = $position[spl_object_id($children[0])];
                $sheets[$first] = $assembled;
                foreach (array_slice($children, 1) as $c) {
                    unset($sheets[$position[spl_object_id($c)]]);
                }
                $sheets = array_values($sheets);

                $inventory[self::key($parent['format'])]--;
                $inventory[$key] += count($children);
                foreach ($children as $c) {
                    $done[spl_object_id($c)] = true;
                }
                $done[spl_object_id($assembled)] = true;
                $created++;
            }
        }

        return $created;
    }

    /**
     * Available stock formats that can be a parent of $child, by number of
     * parts; within one part count least leftover first, then smaller area.
     *
     * @param  StockFormat[]      $formats   Ascending area, registration order within ties.
     * @param  array<string, int> $inventory
     * @return array<int, array<int, array{format: StockFormat, parts: int, columns: int, rows: int, gapX: float, gapY: float, leftover: float}>>
     *         parts (ascending) => parent options
     */
    private function parents(StockFormat $child, array $formats, array $inventory, UpsizeMethod $method): array
    {
        $out  = [];
        $seen = [];
        foreach ($formats as $f) {
            $key = self::key($f);
            if (isset($seen[$key])) {
                continue;   // registered twice: one entry, the inventory is pooled
            }
            $seen[$key] = true;
            if (($inventory[$key] ?? 0) <= 0) {
                continue;
            }

            $columns = self::fits($f->width, $child->width, $method->maxWidthMultiple);
            $rows    = self::fits($f->height, $child->height, $method->maxHeightMultiple);
            $parts   = $columns * $rows;
            if ($parts < 2) {
                continue;
            }
            $leftX = $f->width - $columns * $child->width;
            $leftY = $f->height - $rows * $child->height;
            if ($leftX > $method->widthTolerance + self::EPS || $leftY > $method->heightTolerance + self::EPS) {
                continue;
            }

            $out[$parts][] = [
                'format'   => $f,
                'parts'    => $parts,
                'columns'  => $columns,
                'rows'     => $rows,
                'gapX'     => $this->gap($columns, $leftX),
                'gapY'     => $this->gap($rows, $leftY),
                'leftover' => $f->area() - $parts * $child->area(),
            ];
        }

        foreach ($out as &$list) {
            usort($list, fn(array $a, array $b) => $a['leftover'] <=> $b['leftover']);   // stable: area breaks ties
        }
        unset($list);
        ksort($out);
        return $out;
    }

    /**
     * LAST_SHEET: one parent for the last sheet and up to parts − 1 others –
     * the fewest empty parts, then the most sheets nested, then the least
     * leftover, then the smaller format.
     *
     * @param  array<int, array<int, array<string, mixed>>> $options See parents().
     * @return array<int, array<string, mixed>>             [parent option]
     */
    private function planOneParent(int $n, array $options): array
    {
        $best = null;
        $rank = null;
        foreach ($options as $parts => $list) {
            foreach ($list as $option) {
                $r = [max(0, $parts - $n), -min($parts, $n), $option['leftover']];
                if ($rank === null || $r < $rank) {
                    $rank = $r;
                    $best = $option;
                }
            }
        }
        return [$best];
    }

    /**
     * ALL_SHEETS: parents for a group of $n sheets – the most sheets nested,
     * then the fewest empty parts, then the fewest parents (bounded knapsack
     * over the total number of parts, stock per part count pooled). Within one
     * part count the parents are taken in the order of parents().
     *
     * @param  array<int, array<int, array<string, mixed>>> $options   See parents().
     * @param  array<string, int>                           $inventory By value: consumed locally.
     * @return array<int, array<string, mixed>>             Parent options, larger parents first.
     */
    private function planParents(int $n, array $options, array $inventory): array
    {
        $partCounts = array_keys($options);                  // ascending
        $limit      = $n + max($partCounts) - 1;              // more parts would leave a parent empty

        $stock = [];
        foreach ($options as $parts => $list) {
            $sum = 0;
            foreach ($list as $option) {
                $sum += $inventory[self::key($option['format'])];
                if ($sum >= $n) {
                    break;
                }
            }
            $stock[$parts] = min($sum, $n);
        }

        // $reach[T] = parents per part count with exactly T parts in total, fewest parents.
        $reach = [0 => array_fill_keys($partCounts, 0)];
        foreach ($partCounts as $parts) {
            $copies = min($stock[$parts], intdiv($limit, $parts));
            for ($k = 0; $k < $copies; $k++) {
                for ($t = $limit - $parts; $t >= 0; $t--) {
                    if (!isset($reach[$t])) {
                        continue;
                    }
                    $cand = $reach[$t];
                    $cand[$parts]++;
                    $next = $t + $parts;
                    if (!isset($reach[$next]) || array_sum($cand) < array_sum($reach[$next])) {
                        $reach[$next] = $cand;
                    }
                }
            }
        }

        $pick = null;
        $rank = null;
        foreach ($reach as $total => $counts) {
            if ($total === 0) {
                continue;
            }
            $r = [-min($n, $total), max(0, $total - $n), array_sum($counts)];
            if ($rank === null || $r < $rank) {
                $rank = $r;
                $pick = $counts;
            }
        }
        if ($pick === null) {
            return [];
        }

        $plan = [];
        foreach (array_reverse($partCounts) as $parts) {
            $need = $pick[$parts];
            foreach ($options[$parts] as $option) {
                $key = self::key($option['format']);
                while ($need > 0 && $inventory[$key] > 0) {
                    $plan[] = $option;
                    $inventory[$key]--;
                    $need--;
                }
            }
        }
        return $plan;
    }

    /**
     * The parent sheet: the children's layouts in its parts, row by row.
     *
     * @param array<string, mixed> $parent   See parents().
     * @param Sheet[]              $children Same format as $child, in part order.
     */
    private function assemble(array $parent, StockFormat $child, array $children): Sheet
    {
        /** @var StockFormat $format */
        $format = $parent['format'];
        $sheet  = new Sheet($children[0]->index, new StockFormat($format->width, $format->height, $format->stock));
        $sheet->freeRects = [];   // not used after nesting

        foreach (array_values($children) as $i => $c) {
            $ox = ($i % $parent['columns']) * ($child->width + $parent['gapX']);
            $oy = intdiv($i, $parent['columns']) * ($child->height + $parent['gapY']);
            foreach ($c->pieces as $p) {
                // With a kerf between the parts, the edge on a part's origin is
                // a piece edge after that kerf like any other; without one it
                // is the dividing cut (see PlacedPiece).
                $sheet->pieces[] = new PlacedPiece(
                    $p->x + $ox, $p->y + $oy, $p->width, $p->height, $p->rotated, $p->line,
                    $parent['gapX'] > 0.0 ? $p->originX : $p->originX + $ox,
                    $parent['gapY'] > 0.0 ? $p->originY : $p->originY + $oy
                );
            }
            $sheet->nestedSheets[] = [$ox, $oy, $child->width, $child->height];
        }

        $sheet->upsizedFrom    = new StockFormat($child->width, $child->height, $child->stock);
        $sheet->upsizeMethod   = UpsizeMethod::MULTIPLE;
        $sheet->nestedCapacity = $parent['parts'];
        return $sheet;
    }

    /** The kerf between $count parts in a row when the leftover leaves room for it, else 0. */
    private function gap(int $count, float $leftover): float
    {
        return $count > 1 && $this->kerf > 0.0 && $leftover + self::EPS >= ($count - 1) * $this->kerf
            ? $this->kerf
            : 0.0;
    }

    /** How many $inner fit into $outer side by side (within 1e-6 mm), at most $max. */
    private static function fits(float $outer, float $inner, int $max): int
    {
        return $inner > 0.0 ? min($max, (int) floor(($outer + self::EPS) / $inner)) : 0;
    }

    private static function key(StockFormat $f): string
    {
        return $f->width . ':' . $f->height;
    }
}
