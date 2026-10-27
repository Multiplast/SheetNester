<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Result\NestingResult;

/**
 * Runs the nesting pipeline (pack → eliminate sheets → reduce formats → build
 * result) over one or many piece orderings and keeps the best result.
 *
 * All objectives share the same pipeline; Search decides how many orderings
 * are tried and the Scorer how candidates compare: fewest unplaced pieces,
 * then lowest primary cost, then the objective's tie-break.
 *
 * @internal
 */
final class Solver
{
    public function __construct(
        private GuillotinePacker $packer,
        private SheetEliminator  $eliminator,
        private FormatReducer    $reducer,
        private ResultBuilder    $builder,
        private Scorer           $scorer,
        private Search           $search,
    ) {
    }

    /**
     * @param  array<int, array{float, float}> $allPieces  FFD-sorted expanded pieces.
     * @return array{NestingResult, Sheet[]}  [result incl. passes / timeBudgetHit, sheets]
     */
    public function solve(array $allPieces): array
    {
        if ($this->search->isSinglePass()) {
            return $this->solveSinglePass($allPieces);
        }
        return $this->solveMultiPass($allPieces);
    }

    /**
     * Single pass in FFD order. seed and timeBudget are not used.
     *
     * @param array<int, array{float, float}> $allPieces
     */
    private function solveSinglePass(array $allPieces): array
    {
        [$result, $sheets] = $this->runPipeline($allPieces);
        $result->passes        = 1;
        $result->timeBudgetHit = false;
        return [$result, $sheets];
    }

    /**
     * Multi-pass: the pipeline repeated over multiple piece orderings.
     *
     * Orderings tested (duplicates skipped via a canonical key):
     *   pass 0        – canonical FFD (area descending, always runs),
     *   portfolio     – deterministic orderings: max side / width / height /
     *                   perimeter descending (cheap, often beats random),
     *   further       – `restarts` random shuffles (seeded → reproducible).
     *
     * For each ordering the pipeline runs and the candidate is evaluated:
     * primary = fewest unplaced pieces, secondary = lowest primary cost
     * (Scorer::primaryCost); on a primary-cost tie min_cuts / best_remnant
     * apply their tie-break (Scorer::tiebreakScore, computed lazily only for
     * tied candidates). The best result across all orderings is kept.
     *
     * @param array<int, array{float, float}> $allPieces
     */
    private function solveMultiPass(array $allPieces): array
    {
        $bestResult   = null;
        $bestSheets   = [];
        $bestPrimary  = PHP_FLOAT_MAX;
        $bestTiebreak = null;
        $tested       = 0;
        $budgetHit    = false;
        $deadline     = $this->search->timeBudgetMs !== null
            ? microtime(true) + $this->search->timeBudgetMs / 1000
            : null;

        // If a seed is set, initialise the RNG once so results are reproducible.
        if ($this->search->seed !== null) {
            mt_srand($this->search->seed);
        }

        // Pass 0 = canonical FFD, then deterministic portfolio, then shuffles.
        // Identical orderings (common with many same-size pieces) run only once.
        $orderings = [$allPieces];
        $seen      = [$this->orderingKey($allPieces) => true];

        if ($this->search->portfolio) {
            foreach ($this->deterministicOrderings($allPieces) as $ordered) {
                $key = $this->orderingKey($ordered);
                if (!isset($seen[$key])) {
                    $seen[$key]  = true;
                    $orderings[] = $ordered;
                }
            }
        }
        for ($i = 0; $i < $this->search->restarts; $i++) {
            $shuffled = $allPieces;
            shuffle($shuffled);   // uses mt_rand internally
            $key = $this->orderingKey($shuffled);
            if (!isset($seen[$key])) {
                $seen[$key]  = true;
                $orderings[] = $shuffled;
            }
        }
        unset($seen);

        foreach ($orderings as $orderingIdx => $pieces) {
            // Time budget: the canonical FFD pass (index 0) always runs,
            // further restarts stop once the budget is exhausted.
            if ($deadline !== null && $orderingIdx > 0 && microtime(true) > $deadline) {
                $budgetHit = true;
                break;
            }

            [$result, $sheets] = $this->runPipeline($pieces);
            $tested++;

            $unplacedCount = $result->unplacedCount();
            $bestUnplaced  = $bestResult !== null ? $bestResult->unplacedCount() : PHP_INT_MAX;

            // Compare: fewer unplaced wins; tie → lower primary cost; primary
            // tie → objective tie-break.
            $candPrimary  = $this->scorer->primaryCost($result);
            $isBetter     = false;
            $candTiebreak = null;

            if ($unplacedCount < $bestUnplaced) {
                $isBetter = true;
            } elseif ($unplacedCount === $bestUnplaced) {
                if ($candPrimary < $bestPrimary - 1e-9) {
                    $isBetter = true;
                } elseif ($this->scorer->hasTiebreak()
                    && abs($candPrimary - $bestPrimary) <= 1e-9
                ) {
                    // Lazy: tie-break scores are computed only for tied candidates
                    // (and once for the current best, then cached).
                    $candTiebreak = $this->scorer->tiebreakScore($result, $sheets);
                    if ($bestTiebreak === null) {
                        $bestTiebreak = $this->scorer->tiebreakScore($bestResult, $bestSheets);
                    }
                    if ($candTiebreak < $bestTiebreak - 1e-9) {
                        $isBetter = true;
                    }
                }
            }

            if ($isBetter) {
                $bestResult   = $result;
                $bestSheets   = $sheets;
                $bestPrimary  = $candPrimary;
                // Known only when the win came through the tie-break branch;
                // otherwise recomputed lazily on the next primary-cost tie.
                $bestTiebreak = $candTiebreak;
            }
        }

        $bestResult->passes        = $tested;
        $bestResult->timeBudgetHit = $budgetHit;
        return [$bestResult, $bestSheets];
    }

    /**
     * One run of the pipeline on a given piece ordering.
     *
     * @param  array<int, array{float, float}> $pieces
     * @return array{NestingResult, Sheet[]}
     */
    private function runPipeline(array $pieces): array
    {
        // Step 1 – Greedy nesting with lookahead format selection
        [$sheets, $unplaced] = $this->packer->pack($pieces);

        // Step 2 – Try to eliminate the worst-utilised sheet(s)
        $this->eliminator->eliminate($sheets);

        // Step 3 – Post-process: shrink sheet formats where possible
        $this->reducer->reduce($sheets);

        return [$this->builder->build($sheets, $unplaced), $sheets];
    }

    /**
     * Deterministic ordering portfolio: a handful of sensible sort keys often
     * beats hundreds of random shuffles. usort is stable (PHP 8+), so
     * identical dimensions stay adjacent and runs reproduce.
     *
     * @param  array<int, array{float, float}> $allPieces
     * @return array<int, array<int, array{float, float}>>
     */
    private function deterministicOrderings(array $allPieces): array
    {
        $comparators = [
            // max side desc, tie: area desc
            fn($a, $b) => max($b[0], $b[1]) <=> max($a[0], $a[1])
                ?: ($b[0] * $b[1]) <=> ($a[0] * $a[1]),
            // width desc, tie: height desc
            fn($a, $b) => $b[0] <=> $a[0] ?: $b[1] <=> $a[1],
            // height desc, tie: width desc
            fn($a, $b) => $b[1] <=> $a[1] ?: $b[0] <=> $a[0],
            // perimeter desc, tie: area desc
            fn($a, $b) => ($b[0] + $b[1]) <=> ($a[0] + $a[1])
                ?: ($b[0] * $b[1]) <=> ($a[0] * $a[1]),
        ];

        $variants = [];
        foreach ($comparators as $cmp) {
            $sorted = $allPieces;
            usort($sorted, $cmp);
            $variants[] = $sorted;
        }
        return $variants;
    }

    /**
     * Canonical key of a piece ordering. Pieces with identical dimensions are
     * interchangeable, so two orderings with the same dimension sequence give
     * identical nesting results – used to skip duplicate passes.
     *
     * @param array<int, array{float, float}> $pieces
     */
    private function orderingKey(array $pieces): string
    {
        $parts = [];
        foreach ($pieces as [$w, $h]) {
            $parts[] = $w . 'x' . $h;
        }
        return md5(implode(';', $parts));
    }
}
