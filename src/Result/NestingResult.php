<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * Complete outcome of a nesting run: the sheet plans (largest format first),
 * stock usage, unplaced pieces and the totals over all sheets.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class NestingResult
{
    /** Piece orderings tried (1 for a single pass). */
    public int $passes = 1;

    /** True when the time budget stopped further orderings (best-so-far kept). */
    public bool $timeBudgetHit = false;

    /** Name of the objective the run optimised (Options\Objective::MIN_WASTE etc.). */
    public string $objective = '';

    /** Wall-clock time of the whole calculation in milliseconds. */
    public float $calculationMs = 0.0;

    /** Total area (m²) of all marked remnants; null when remnant marking is off. */
    public ?float $remnantM2 = null;

    /** Remnant area as a percentage of the material used; null when marking is off. */
    public ?float $remnantPct = null;

    /**
     * @param SheetPlan[]      $sheets
     * @param StockUsage[]     $stockUsage
     * @param UnplacedPieces[] $unplaced
     * @param float            $wastePctAvg       Unweighted average of per-sheet waste
     *                                            percentages (1.x total_waste_pct);
     *                                            prefer utilizationPct.
     * @param float            $utilizationPct    Area-weighted: piece area / material area.
     * @param float            $materialM2        Material used (all sheets), rounded to 4 dp.
     * @param int              $cutCount          Saw passes over all sheets.
     * @param float            $cutsMm            Total cut length (mm).
     * @param float            $sealMm            Length to seal (mm) – 1.x definition:
     *                                            Σ piece heights × 2.
     */
    public function __construct(
        public array $sheets,
        public array $stockUsage,
        public array $unplaced,
        public float $wastePctAvg,
        public float $utilizationPct,
        public float $materialM2,
        public int   $cutCount,
        public float $cutsMm,
        public float $cutsHorizontalMm,
        public float $cutsVerticalMm,
        public float $sealMm,
    ) {
    }

    /** Number of pieces that could not be placed. */
    public function unplacedCount(): int
    {
        $n = 0;
        foreach ($this->unplaced as $u) {
            $n += $u->qty;
        }
        return $n;
    }

    /**
     * The result in the 1.x array shape of SheetNester::calculate() – key for
     * key and in the same order, so existing consumers and the golden fixtures
     * keep working.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        $out = [
            'sheets'              => array_map(fn(SheetPlan $s) => $s->toLegacyArray(), $this->sheets),
            'stock_usage'         => array_map(fn(StockUsage $u) => $u->toLegacyArray(), $this->stockUsage),
            'unplaced_pieces'     => array_map(fn(UnplacedPieces $u) => $u->toLegacyArray(), $this->unplaced),
            'total_sheets'        => count($this->sheets),
            'total_waste_pct'     => $this->wastePctAvg,
            'utilization_pct'     => $this->utilizationPct,
            'total_material_m2'   => $this->materialM2,
            'total_cuts_count'    => $this->cutCount,
            'total_cuts_mm'       => $this->cutsMm,
            'total_cuts_horiz_mm' => $this->cutsHorizontalMm,
            'total_cuts_vert_mm'  => $this->cutsVerticalMm,
            'total_seal_mm'       => $this->sealMm,
            'permutations_tested' => $this->passes,
            'time_budget_hit'     => $this->timeBudgetHit,
        ];
        if ($this->remnantM2 !== null) {
            $out['total_remnant_m2']  = $this->remnantM2;
            $out['total_remnant_pct'] = $this->remnantPct;
        }
        $out['strategy']            = $this->objective;
        $out['calculation_time_ms'] = $this->calculationMs;

        return $out;
    }
}
