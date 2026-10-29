<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\Result\Placement;
use Multiplast\SheetNester\Result\Remnant;
use Multiplast\SheetNester\Result\SheetPlan;
use Multiplast\SheetNester\Result\StockUsage;
use Multiplast\SheetNester\Result\UnplacedPieces;

/**
 * Builds the NestingResult from nested sheets, orders the sheets for the
 * cutting plan and attaches usable remnants.
 *
 * @internal
 */
final class ResultBuilder
{
    /**
     * @param StockFormat[]           $stockFormats Registered formats (for stock usage).
     * @param array<int, string|null> $lineLabels   Piece label per job line index.
     */
    public function __construct(
        private array         $stockFormats,
        private float         $kerf,
        private CutCalculator $cuts,
        private array         $lineLabels = [],
    ) {
    }

    /**
     * Assembles the result of a completed nesting run.
     *
     * @param Sheet[] $sheets
     * @param array<int, array{width: float, height: float, line: int, reason: string}> $unplaced
     *        Packer output, one entry per piece.
     */
    public function build(array $sheets, array $unplaced): NestingResult
    {
        $plans = [];
        foreach ($sheets as $sheet) {
            $placements = [];
            foreach ($sheet->pieces as $p) {
                $placements[] = new Placement(
                    $p->x,
                    $p->y,
                    $p->width,
                    $p->height,
                    $p->rotated,
                    $p->line !== null ? ($this->lineLabels[$p->line] ?? null) : null
                );
            }
            $plan = new SheetPlan(
                $sheet->index,
                $sheet->format->width,
                $sheet->format->height,
                $placements,
                $sheet->usedAreaPct($this->kerf),
                $this->cuts->forSheet($sheet),
                $this->cuts->sealMm($sheet)
            );
            if ($sheet->upsizedFrom !== null) {
                $plan->upsizedFrom = ['width' => $sheet->upsizedFrom->width, 'height' => $sheet->upsizedFrom->height];
            }
            $plans[] = $plan;
        }

        // Stock usage – derived from winning sheets.
        // 'remaining' is computed arithmetically from the registered stock,
        // NOT from the mutated per-run format copies: after format reduction
        // a sheet may carry a fresh StockFormat instance whose counter would be
        // meaningless (full stock), and released slots are only tracked in the
        // reduction's local inventory.
        $stockByKey = [];
        $unlimited  = [];
        foreach ($this->stockFormats as $fmt) {
            $key = $fmt->width . 'x' . $fmt->height;
            if ($fmt->isUnlimited()) {
                $unlimited[$key] = true;
            }
            $stockByKey[$key] = ($stockByKey[$key] ?? 0) + ($fmt->stock ?? 0);
        }

        $usageMap = [];
        foreach ($sheets as $sheet) {
            $key = $sheet->format->width . 'x' . $sheet->format->height;
            if (!isset($usageMap[$key])) {
                $usageMap[$key] = [
                    'width'  => $sheet->format->width,
                    'height' => $sheet->format->height,
                    'used'   => 0,
                ];
            }
            $usageMap[$key]['used']++;
        }
        $usage = [];
        foreach ($usageMap as $key => $u) {
            $usage[] = new StockUsage(
                $u['width'],
                $u['height'],
                $u['used'],
                isset($unlimited[$key]) ? null : max(0, ($stockByKey[$key] ?? 0) - $u['used'])
            );
        }

        // Unplaced pieces grouped by size + label (+ reason, which follows from
        // the size) in first-seen order – identical to the 1.x grouping when
        // no labels are used.
        $groups = [];
        foreach ($unplaced as $u) {
            $label = $this->lineLabels[$u['line']] ?? null;
            foreach ($groups as $g) {
                if ($g->width === $u['width'] && $g->height === $u['height']
                    && $g->label === $label && $g->reasonCode === $u['reason']) {
                    $g->qty++;
                    continue 2;
                }
            }
            $groups[] = new UnplacedPieces($u['width'], $u['height'], 1, $u['reason'], $label);
        }

        // Totals – from the per-sheet (rounded) values, exactly like 1.x.
        $totalWaste       = 0.0;
        $totalMaterialM2  = 0.0;
        $totalPieceM2     = 0.0;
        $totalCutsHorizMm = 0.0;
        $totalCutsVertMm  = 0.0;
        $totalSealMm      = 0.0;
        $totalCutsCount   = 0;

        if (!empty($plans)) {
            foreach ($plans as $s) {
                $totalWaste       += 100 - $s->usedPct;
                $totalMaterialM2  += ($s->width * $s->height) / 1_000_000;
                $totalCutsHorizMm += $s->cuts->horizontalMm;
                $totalCutsVertMm  += $s->cuts->verticalMm;
                $totalSealMm      += $s->sealMm ?? 0.0;
                $totalCutsCount   += $s->cuts->count();
                foreach ($s->pieces as $p) {
                    $totalPieceM2 += ($p->width * $p->height) / 1_000_000;
                }
            }
            $totalWaste = round($totalWaste / count($plans), 2);
        }

        return new NestingResult(
            sheets:           $plans,
            stockUsage:       $usage,
            unplaced:         $groups,
            wastePctAvg:      $totalWaste,
            utilizationPct:   $totalMaterialM2 > 0
                ? round($totalPieceM2 / $totalMaterialM2 * 100, 2)
                : 0.0,
            materialM2:       round($totalMaterialM2, 4),
            cutCount:         $totalCutsCount,
            cutsMm:           round($totalCutsHorizMm + $totalCutsVertMm, 1),
            cutsHorizontalMm: round($totalCutsHorizMm, 1),
            cutsVerticalMm:   round($totalCutsVertMm, 1),
            sealMm:           $this->cuts->hasSeal() ? round($totalSealMm, 1) : null,
        );
    }

    /**
     * Reorders the final sheets by stock-format size – largest area first,
     * ties broken by width then height descending – which also groups
     * identical formats next to each other. Renumbers sheet indices to the
     * new order.
     *
     * Purely a presentation order for the cutting plan (PDF / SVG / result):
     * the operator can pull one format from stock at a time and work through
     * the plan largest → smallest. It does NOT move or re-cut any piece.
     *
     * Reorders $sheets and $result->sheets with the SAME permutation so they
     * stay aligned (attachRemnants relies on this). usort is stable (PHP 8),
     * so identical formats keep their relative order and the result stays
     * deterministic.
     *
     * @param Sheet[] $sheets  Reordered in place.
     */
    public function sortSheetsBySize(array &$sheets, NestingResult $result): void
    {
        $n = count($sheets);
        if ($n < 2 || count($result->sheets) !== $n) {
            return;
        }

        $order = range(0, $n - 1);
        usort($order, function (int $a, int $b) use ($sheets) {
            $fa = $sheets[$a]->format;
            $fb = $sheets[$b]->format;
            return ($fb->area() <=> $fa->area())
                ?: ($fb->width <=> $fa->width)
                ?: ($fb->height <=> $fa->height);
        });

        $newSheets = [];
        $newPlans  = [];
        foreach ($order as $pos => $old) {
            $sheets[$old]->index = $pos;
            $newSheets[] = $sheets[$old];

            $plan        = $result->sheets[$old];
            $plan->index = $pos;
            $newPlans[]  = $plan;
        }

        $sheets         = $newSheets;
        $result->sheets = $newPlans;
    }

    /**
     * Computes usable remnants for the winning sheets and stores them in the
     * result (SheetPlan::$remnants / $remnantM2, NestingResult::$remnantM2 /
     * $remnantPct / $netMaterialM2). One deterministic work budget is shared
     * by all sheets.
     *
     * @param Sheet[] $sheets  Aligned with $result->sheets.
     */
    public function attachRemnants(NestingResult $result, array $sheets, RemnantFinder $finder, string $remnantMode): void
    {
        $totalRemnantM2 = 0.0;
        $workBudget     = RemnantFinder::WORK_BUDGET;

        foreach ($sheets as $i => $sheet) {
            $found = $remnantMode === Remnants::AUTO
                ? $finder->findAuto($sheet, $workBudget)
                : $finder->findDefined($sheet, $workBudget);

            $remnants = [];
            $areaM2   = 0.0;
            foreach ($found as $r) {
                $remnants[] = new Remnant($r->x, $r->y, $r->width, $r->height);
                $areaM2    += $r->area() / 1_000_000;
            }

            if (isset($result->sheets[$i])) {
                $result->sheets[$i]->remnants  = $remnants;
                $result->sheets[$i]->remnantM2 = round($areaM2, 4);
            }
            $totalRemnantM2 += $areaM2;
        }

        $result->remnantM2     = round($totalRemnantM2, 4);
        $result->remnantPct    = $result->materialM2 > 0
            ? round($totalRemnantM2 / $result->materialM2 * 100, 2)
            : 0.0;
        $result->netMaterialM2 = round(max(0.0, $result->materialM2 - $totalRemnantM2), 4);
    }
}
