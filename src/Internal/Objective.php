<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\SheetNester;

/**
 * What a strategy optimises: the primary cost compared right after the
 * unplaced count, and the optional tie-break applied among primary-cost ties.
 *
 * @internal
 */
final class Objective
{
    public function __construct(
        private string        $strategy,
        private float         $cutCostM2,
        private RemnantFinder $remnants,
    ) {
    }

    /**
     * Strategy primary cost – LOWER is better, compared right after the
     * unplaced count:
     *  - min_waste / best_remnant: total material (m²),
     *  - min_cuts: total material (m²) + cutCostM2 × number of saw passes –
     *    the weighted saw-time objective (a saved cut is "worth" cutCostM2
     *    of material).
     *
     * @param array<string, mixed> $result
     */
    public function primaryCost(array $result): float
    {
        if ($this->strategy === SheetNester::STRATEGY_MIN_CUTS) {
            return $result['total_material_m2']
                + $this->cutCostM2 * $result['total_cuts_count'];
        }
        return $result['total_material_m2'];
    }

    /** Whether primary-cost ties are broken by tiebreakScore() (min_cuts, best_remnant). */
    public function hasTiebreak(): bool
    {
        return $this->strategy !== SheetNester::STRATEGY_MIN_WASTE;
    }

    /**
     * Strategy-specific tie-break score – LOWER is better. Applied only among
     * candidates tied on unplaced count AND primary cost:
     *  - min_cuts:     total internal cut length in mm (shorter saw path),
     *  - best_remnant: negative area (m²) of the largest single empty
     *                  rectangle across all sheets (prefer one big offcut).
     *
     * @param array<string, mixed> $result
     * @param Sheet[]              $sheets  Post-reduction sheets of the candidate result.
     */
    public function tiebreakScore(array $result, array $sheets): float
    {
        if ($this->strategy === SheetNester::STRATEGY_MIN_CUTS) {
            return (float) $result['total_cuts_mm'];
        }
        return -$this->remnants->largestSingleRemnantM2($sheets);
    }
}
