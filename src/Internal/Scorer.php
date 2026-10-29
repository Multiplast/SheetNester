<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Result\NestingResult;

/**
 * Scores candidate plans for the Solver according to the job's Objective:
 * the primary cost compared right after the unplaced count, and the optional
 * tie-break applied among primary-cost ties.
 *
 * @internal
 */
final class Scorer
{
    public function __construct(
        private Objective     $objective,
        private RemnantFinder $remnants,
    ) {
    }

    /**
     * Primary cost – LOWER is better, compared right after the unplaced count:
     *  - min_waste / best_remnant: total material (m²),
     *  - min_cuts: total material (m²) + cutCostM2 × number of saw passes –
     *    the weighted saw-time objective (a saved cut is "worth" cutCostM2
     *    of material).
     */
    public function primaryCost(NestingResult $result): float
    {
        if ($this->objective->name === Objective::MIN_CUTS) {
            return $result->materialM2 + $this->objective->cutCostM2 * $result->cutCount;
        }
        return $result->materialM2;
    }

    /** Whether primary-cost ties are broken by tiebreakScore() (min_cuts, best_remnant). */
    public function hasTiebreak(): bool
    {
        return $this->objective->name !== Objective::MIN_WASTE;
    }

    /**
     * Tie-break score – LOWER is better. Applied only among candidates tied on
     * unplaced count AND primary cost:
     *  - min_cuts:     total internal cut length in mm (shorter saw path),
     *  - best_remnant: negative area (m²) of the largest single empty
     *                  rectangle across all sheets (prefer one big offcut;
     *                  independent of the Remnants minimum and formats).
     *
     * @param Sheet[] $sheets  Post-reduction sheets of the candidate result.
     */
    public function tiebreakScore(NestingResult $result, array $sheets): float
    {
        if ($this->objective->name === Objective::MIN_CUTS) {
            return $result->cutsMm;
        }
        return -$this->remnants->largestSingleRemnantM2($sheets);
    }
}
