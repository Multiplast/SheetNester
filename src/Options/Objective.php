<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * What the solver minimises when it compares candidate plans. Fewer unplaced
 * pieces always wins first; the objective decides among plans that place the
 * same number of pieces.
 *
 *  - minWaste():    least material (m²).
 *  - minCuts():     material_m2 + cutCostM2 × saw passes – may trade a little
 *                   material for noticeably fewer cuts; ties → shorter cut length.
 *  - bestRemnant(): least material; ties → the plan whose largest single
 *                   empty rectangle is biggest (one big offcut back to stock).
 *                   Independent of the Remnants option – the rectangle is
 *                   measured without its minimum size or formats.
 *
 * No objective trades material for a remnant; see Options\Upsize for that.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Objective
{
    public const MIN_WASTE    = 'min_waste';
    public const MIN_CUTS     = 'min_cuts';
    public const BEST_REMNANT = 'best_remnant';

    private function __construct(
        public string $name,
        public float  $cutCostM2,
    ) {
    }

    public static function minWaste(): self
    {
        return new self(self::MIN_WASTE, 0.0);
    }

    /**
     * @param float $cutCostM2 Material-equivalent value of one saw pass, in m²:
     *                         0.05 means "one saved cut is worth 0.05 m² of
     *                         material". 0 = never trade material, only break
     *                         ties by cuts. Note that material changes in whole
     *                         sheets, so values well below one sheet area rarely
     *                         change the plan.
     */
    public static function minCuts(float $cutCostM2 = 0.05): self
    {
        if (!is_finite($cutCostM2) || $cutCostM2 < 0) {
            throw new \InvalidArgumentException('cutCostM2 must be a finite number >= 0.');
        }
        return new self(self::MIN_CUTS, $cutCostM2);
    }

    public static function bestRemnant(): self
    {
        return new self(self::BEST_REMNANT, 0.0);
    }
}
