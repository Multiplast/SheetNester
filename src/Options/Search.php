<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * How much effort the solver spends: which piece orderings it tries.
 *
 * The canonical First Fit Decreasing pass always runs. With $portfolio a
 * handful of deterministic orderings (max side / width / height / perimeter)
 * follow, then $restarts random shuffles. singlePass() runs the FFD pass only.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Search
{
    /**
     * @param int        $restarts     Random piece-order restarts (0 = none).
     * @param int|null   $seed         RNG seed; with a seed the result is reproducible.
     * @param float|null $timeBudgetMs Soft limit for the whole solve() in ms: the FFD
     *                                 pass always runs, further orderings are skipped
     *                                 once the budget is spent (best-so-far is kept).
     * @param bool       $portfolio    Also try the deterministic ordering portfolio.
     */
    public function __construct(
        public int    $restarts = 20,
        public ?int   $seed = null,
        public ?float $timeBudgetMs = null,
        public bool   $portfolio = true,
    ) {
        if ($restarts < 0) {
            throw new \InvalidArgumentException('restarts must be >= 0.');
        }
        if ($timeBudgetMs !== null && (!is_finite($timeBudgetMs) || $timeBudgetMs <= 0)) {
            throw new \InvalidArgumentException('timeBudgetMs must be a positive number or null.');
        }
    }

    /** One FFD pass only – the fastest option (a few ms); seed and time budget are irrelevant. */
    public static function singlePass(): self
    {
        return new self(0, null, null, false);
    }

    public function isSinglePass(): bool
    {
        return $this->restarts === 0 && !$this->portfolio;
    }
}
