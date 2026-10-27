<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

use Multiplast\SheetNester\Internal\CutCalculator;
use Multiplast\SheetNester\Internal\FormatReducer;
use Multiplast\SheetNester\Internal\GuillotinePacker;
use Multiplast\SheetNester\Internal\Piece;
use Multiplast\SheetNester\Internal\RemnantFinder;
use Multiplast\SheetNester\Internal\ResultBuilder;
use Multiplast\SheetNester\Internal\Scorer;
use Multiplast\SheetNester\Internal\SheetEliminator;
use Multiplast\SheetNester\Internal\Solver;
use Multiplast\SheetNester\Result\NestingResult;

/**
 * SheetNester – 2D guillotine bin packing of rectangular pieces onto stock
 * sheets (plastic sheet materials such as polycarbonate, but any rectangular
 * stock works).
 *
 *   $result = (new SheetNester())->solve($job);   // NestingJob → NestingResult
 *
 * Algorithm: Best Area Fit placement with Longer Axis Split (guillotine cuts)
 * in First Fit Decreasing order; when a new sheet has to be opened, the stock
 * format is chosen by a capped lookahead utilisation score. After every pass
 * the engine tries to dissolve the worst-utilised sheet into the others and to
 * shrink sheet formats. Options\Search decides how many piece orderings are
 * tried (single FFD pass, deterministic portfolio, seeded random restarts),
 * Options\Objective how candidate plans compare.
 *
 * This class keeps no state between solve() calls and validates nothing
 * itself – NestingJob and the option objects do. The work is done by the
 * classes in Internal\ (GuillotinePacker, SheetEliminator, FormatReducer,
 * Solver, Scorer, CutCalculator, RemnantFinder, ResultBuilder).
 */
final class SheetNester
{
    /**
     * Runs the nesting for the given job.
     *
     * Pipeline: expand + FFD-sort the pieces → Solver (one or many orderings of
     * pack → eliminate sheets → reduce formats → build result) → order the
     * sheets largest format first → attach usable remnants (winning sheets
     * only, never inside the restart loop).
     */
    public function solve(NestingJob $job): NestingResult
    {
        $startTime = microtime(true);

        $material  = $job->material();
        $remnants  = $job->remnants();
        $allPieces = $this->expandAndSort($job->pieces());

        $labels  = array_map(fn(Piece $p) => $p->label, $job->pieces());
        $packer  = new GuillotinePacker($material->kerf, $material->allowsRotation(), $job->stock());
        $finder  = new RemnantFinder($material->kerf, $material->allowsRotation(), $remnants->minSide, $remnants->formats);
        $builder = new ResultBuilder($job->stock(), $material->kerf, new CutCalculator($material->grain, $material->kerf), $labels);
        $solver  = new Solver(
            $packer,
            new SheetEliminator($packer),
            new FormatReducer($job->stock(), $material->kerf),
            $builder,
            new Scorer($job->objective(), $finder),
            $job->search(),
        );

        [$result, $sheets] = $solver->solve($allPieces);

        // Order the final sheets largest format first (grouping identical
        // formats) so the operator can pull one format from stock at a time.
        $builder->sortSheetsBySize($sheets, $result);

        // Usable remnants – computed once, for the winning sheets only.
        if ($remnants->enabled()) {
            $builder->attachRemnants($result, $sheets, $finder, $remnants->mode);
        }

        $result->objective     = $job->objective()->name;
        $result->calculationMs = round((microtime(true) - $startTime) * 1000, 2);

        return $result;
    }

    /**
     * Expands all pieces into individual [width, height, line] tuples (line =
     * index of the job's piece line) and sorts them by area descending (FFD).
     *
     * @param  Piece[] $pieces
     * @return array<int, array{float, float, int}>
     */
    private function expandAndSort(array $pieces): array
    {
        $all = [];
        foreach (array_values($pieces) as $line => $piece) {
            for ($i = 0; $i < $piece->qty; $i++) {
                $all[] = [$piece->width, $piece->height, $line];
            }
        }
        usort($all, fn($a, $b) => ($b[0] * $b[1]) <=> ($a[0] * $a[1]));
        return $all;
    }
}
