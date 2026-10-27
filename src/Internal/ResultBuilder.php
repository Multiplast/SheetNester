<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\SheetNester;

/**
 * Assembles the public result array from nested sheets, orders the sheets for
 * the cutting plan and attaches usable remnants.
 *
 * @internal
 */
final class ResultBuilder
{
    /**
     * @param StockFormat[] $stockFormats Registered formats (for stock_usage).
     */
    public function __construct(
        private array         $stockFormats,
        private float         $kerf,
        private CutCalculator $cuts,
    ) {
    }

    /**
     * Assembles the public result array from a completed nesting run.
     *
     * @param Sheet[] $sheets
     * @param array   $unplaced
     */
    public function build(array $sheets, array $unplaced): array
    {
        $sheetsOut = [];
        foreach ($sheets as $sheet) {
            $piecesOut = [];
            foreach ($sheet->pieces as $p) {
                $piecesOut[] = [
                    'width'   => $p->width,
                    'height'  => $p->height,
                    'x'       => $p->x,
                    'y'       => $p->y,
                    'rotated' => $p->rotated,
                ];
            }
            $cuts = $this->cuts->forSheet($sheet);
            $sheetsOut[] = [
                'sheet_index'   => $sheet->index,
                'stock_width'   => $sheet->format->width,
                'stock_height'  => $sheet->format->height,
                'pieces'        => $piecesOut,
                'used_area_pct' => $sheet->usedAreaPct($this->kerf),
                'cuts'          => $cuts,
            ];
        }

        // Stock usage – derived from winning sheets.
        // 'remaining' is computed arithmetically from the registered stock,
        // NOT from the mutated per-run format copies: after format reduction
        // a sheet may carry a fresh StockFormat instance whose counter would be
        // meaningless (full stock), and released slots are only tracked in the
        // reduction's local inventory.
        $stockByKey = [];
        foreach ($this->stockFormats as $fmt) {
            $key = $fmt->width . 'x' . $fmt->height;
            $stockByKey[$key] = ($stockByKey[$key] ?? 0) + $fmt->stock;
        }

        $usageMap = [];
        foreach ($sheets as $sheet) {
            $key = $sheet->format->width . 'x' . $sheet->format->height;
            if (!isset($usageMap[$key])) {
                $usageMap[$key] = [
                    'width'     => $sheet->format->width,
                    'height'    => $sheet->format->height,
                    'used'      => 0,
                    'remaining' => 0,
                ];
            }
            $usageMap[$key]['used']++;
        }
        foreach ($usageMap as $key => $u) {
            $usageMap[$key]['remaining'] = max(0, ($stockByKey[$key] ?? 0) - $u['used']);
        }

        $totalWaste      = 0.0;
        $totalMaterialM2 = 0.0;
        $totalPieceM2    = 0.0;
        $totalCutsHorizMm = 0.0;
        $totalCutsVertMm  = 0.0;
        $totalCutsSealMm  = 0.0;
        $totalCutsCount   = 0;

        if (!empty($sheetsOut)) {
            foreach ($sheetsOut as $s) {
                $totalWaste       += 100 - $s['used_area_pct'];
                $totalMaterialM2  += ($s['stock_width'] * $s['stock_height']) / 1_000_000;
                $totalCutsHorizMm += $s['cuts']['horizontal_mm'];
                $totalCutsVertMm  += $s['cuts']['vertical_mm'];
                $totalCutsSealMm  += $s['cuts']['seal_mm'];
                $totalCutsCount   += count($s['cuts']['cuts_horizontal'])
                                   + count($s['cuts']['cuts_vertical']);
                foreach ($s['pieces'] as $p) {
                    $totalPieceM2 += ($p['width'] * $p['height']) / 1_000_000;
                }
            }
            $totalWaste = round($totalWaste / count($sheetsOut), 2);
        }

        return [
            'sheets'                => $sheetsOut,
            'stock_usage'           => array_values($usageMap),
            'unplaced_pieces'       => $unplaced,
            'total_sheets'          => count($sheetsOut),
            // Unweighted average of per-sheet waste percentages (kept for BC).
            // Prefer 'utilization_pct' – area-weighted, consistent with the PDF summary.
            'total_waste_pct'       => $totalWaste,
            'utilization_pct'       => $totalMaterialM2 > 0
                ? round($totalPieceM2 / $totalMaterialM2 * 100, 2)
                : 0.0,
            'total_material_m2'     => round($totalMaterialM2, 4),
            // Merged collinear segments ≈ number of saw passes (proxy).
            'total_cuts_count'      => $totalCutsCount,
            'total_cuts_mm'         => round($totalCutsHorizMm + $totalCutsVertMm, 1),
            'total_cuts_horiz_mm'   => round($totalCutsHorizMm, 1),
            'total_cuts_vert_mm'    => round($totalCutsVertMm, 1),
            'total_seal_mm'          => round($totalCutsSealMm, 1),
        ];
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
     * Reorders $sheets and $result['sheets'] with the SAME permutation so they
     * stay aligned (attachRemnants relies on this). usort is stable (PHP 8),
     * so identical formats keep their relative order and the result stays
     * deterministic.
     *
     * @param Sheet[] $sheets  Reordered in place.
     */
    public function sortSheetsBySize(array &$sheets, array $result): array
    {
        $n = count($sheets);
        if ($n < 2 || count($result['sheets']) !== $n) {
            return $result;
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
        $newOut    = [];
        foreach ($order as $pos => $old) {
            $sheets[$old]->index = $pos;
            $newSheets[] = $sheets[$old];

            $row = $result['sheets'][$old];
            $row['sheet_index'] = $pos;
            $newOut[] = $row;
        }

        $sheets           = $newSheets;
        $result['sheets'] = $newOut;
        return $result;
    }

    /**
     * Computes usable remnants for the winning sheets and injects them into
     * the result array: sheets[i]['remnants'] (list of {x, y, width, height}),
     * sheets[i]['remnant_area_m2'], total_remnant_m2, total_remnant_pct.
     *
     * One deterministic work budget is shared by all sheets of the result.
     *
     * @param Sheet[] $sheets  Aligned with $result['sheets'].
     */
    public function attachRemnants(array $result, array $sheets, RemnantFinder $finder, string $remnantMode): array
    {
        $totalRemnantM2 = 0.0;
        $workBudget     = RemnantFinder::WORK_BUDGET;

        foreach ($sheets as $i => $sheet) {
            $remnants = $remnantMode === SheetNester::REMNANT_AUTO
                ? $finder->findAuto($sheet, $workBudget)
                : $finder->findDefined($sheet, $workBudget);

            $remnantsOut = [];
            $areaM2      = 0.0;
            foreach ($remnants as $r) {
                $remnantsOut[] = [
                    'x'      => $r->x,
                    'y'      => $r->y,
                    'width'  => $r->width,
                    'height' => $r->height,
                ];
                $areaM2 += $r->area() / 1_000_000;
            }

            if (isset($result['sheets'][$i])) {
                $result['sheets'][$i]['remnants']        = $remnantsOut;
                $result['sheets'][$i]['remnant_area_m2'] = round($areaM2, 4);
            }
            $totalRemnantM2 += $areaM2;
        }

        $result['total_remnant_m2']  = round($totalRemnantM2, 4);
        $result['total_remnant_pct'] = ($result['total_material_m2'] ?? 0) > 0
            ? round($totalRemnantM2 / $result['total_material_m2'] * 100, 2)
            : 0.0;

        return $result;
    }
}
