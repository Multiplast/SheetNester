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
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Render\SvgRenderer;
use Multiplast\SheetNester\Result\NestingResult;

/**
 * SheetNester – 2D bin packing for plastic sheet materials (polycarbonate etc.)
 *
 * Uses the Guillotine Cut algorithm with Best Area Fit (BAF) heuristic
 * and First Fit Decreasing (FFD) piece ordering. New-sheet formats are chosen
 * by a capped lookahead utilisation score; after each pass the engine tries
 * to eliminate the worst-utilised sheet (repack into the others) and to
 * shrink sheet formats (format reduction). Options\Search decides how many
 * piece orderings are tried, Options\Objective how candidates compare.
 *
 *   $result = (new SheetNester())->solve($job);   // NestingJob → NestingResult
 *
 * The 1.x constructor / addStockFormat() / addPiece() / calculate() / toSvg()
 * API is still available on the same class and is deprecated: it builds a
 * NestingJob from the old arguments and returns NestingResult::toLegacyArray().
 *
 * This class validates nothing itself – NestingJob and the option objects do –
 * and keeps no state between solve() calls. The work is done by the classes in
 * Internal\ (GuillotinePacker, SheetEliminator, FormatReducer, Solver, Scorer,
 * CutCalculator, RemnantFinder, ResultBuilder).
 */
class SheetNester
{
    /** @deprecated 1.x strategy names – use Options\Objective and Options\Search. */
    public const STRATEGY_SMALLEST_FIT    = 'smallest_fit';
    /** @deprecated */
    public const STRATEGY_MIN_WASTE       = 'min_waste';
    /** @deprecated */
    public const STRATEGY_MIN_CUTS        = 'min_cuts';
    /** @deprecated */
    public const STRATEGY_BEST_REMNANT    = 'best_remnant';

    /** @deprecated Default restarts of the 1.x constructor; Options\Search has its own default. */
    public const MIN_WASTE_RESTARTS = 20;

    /** @deprecated 1.x remnant modes – use Options\Remnants. */
    public const REMNANT_NONE    = 'none';
    /** @deprecated */
    public const REMNANT_AUTO    = 'auto';
    /** @deprecated */
    public const REMNANT_DEFINED = 'defined';

    // -------------------------------------------------------------------------
    // 2.0 API
    // -------------------------------------------------------------------------

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
        $builder = new ResultBuilder($job->stock(), $material->kerf, new CutCalculator($material->grain), $labels);
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

    // -------------------------------------------------------------------------
    // 1.x API (deprecated adapter over solve())
    // -------------------------------------------------------------------------

    /** @var NestingJob|null  Job under construction by the 1.x methods. */
    private ?NestingJob $legacyJob = null;

    /** @var array<string, mixed>|null  1.x constructor arguments (job is built lazily). */
    private ?array $legacyOptions = null;

    /** @var array<int, array{float, float, int}> */
    private array $legacyStock = [];

    /** @var array<int, array{float, float, int}> */
    private array $legacyPieces = [];

    /** @var array<int, array{float, float}> */
    private array $legacyRemnantFormats = [];

    /** Result of the last calculate(), used by toSvg(). */
    private ?NestingResult $lastResult = null;

    /**
     * @deprecated Build a NestingJob and call solve() instead. Mapping of the
     *             1.x arguments: kerf/grainDirection → Options\Material
     *             ('horizontal' = Grain::ALONG_WIDTH, 'vertical' = Grain::ALONG_HEIGHT),
     *             formatStrategy → Options\Objective (+ Options\Search::singlePass()
     *             for 'smallest_fit'), restarts/seed/timeBudgetMs → Options\Search,
     *             remnantMode/minRemnantSide/addRemnantFormat() → Options\Remnants.
     *
     * @param float|null $kerf  null = plain 2.0 instance (solve() only).
     */
    public function __construct(
        ?float $kerf           = null,
        string $grainDirection = 'none',
        string $formatStrategy = self::STRATEGY_SMALLEST_FIT,
        int    $restarts       = self::MIN_WASTE_RESTARTS,
        ?int   $seed           = null,
        ?float $timeBudgetMs   = null,
        string $remnantMode    = self::REMNANT_NONE,
        float  $minRemnantSide = 200.0,
        float  $cutCostM2      = 0.05,
    ) {
        if ($kerf === null) {
            return;
        }
        if (!in_array($grainDirection, ['horizontal', 'vertical', 'none'], true)) {
            throw new \InvalidArgumentException(
                "grainDirection must be 'horizontal', 'vertical', or 'none'."
            );
        }
        $strategies = [
            self::STRATEGY_SMALLEST_FIT,
            self::STRATEGY_MIN_WASTE,
            self::STRATEGY_MIN_CUTS,
            self::STRATEGY_BEST_REMNANT,
        ];
        if (!in_array($formatStrategy, $strategies, true)) {
            throw new \InvalidArgumentException(
                "formatStrategy must be one of: 'smallest_fit', 'min_waste', 'min_cuts', 'best_remnant'."
            );
        }
        if ($restarts < 0) {
            throw new \InvalidArgumentException('restarts must be >= 0.');
        }
        if ($timeBudgetMs !== null && $timeBudgetMs <= 0) {
            throw new \InvalidArgumentException('timeBudgetMs must be positive or null.');
        }
        if (!in_array($remnantMode, [self::REMNANT_NONE, self::REMNANT_AUTO, self::REMNANT_DEFINED], true)) {
            throw new \InvalidArgumentException(
                "remnantMode must be 'none', 'auto', or 'defined'."
            );
        }
        if ($minRemnantSide <= 0) {
            throw new \InvalidArgumentException('minRemnantSide must be positive.');
        }
        if ($cutCostM2 < 0) {
            throw new \InvalidArgumentException('cutCostM2 must be >= 0.');
        }
        $this->legacyOptions = [
            'kerf'           => $kerf,
            'grainDirection' => $grainDirection,
            'formatStrategy' => $formatStrategy,
            'restarts'       => $restarts,
            'seed'           => $seed,
            'timeBudgetMs'   => $timeBudgetMs,
            'remnantMode'    => $remnantMode,
            'minRemnantSide' => $minRemnantSide,
            'cutCostM2'      => $cutCostM2,
        ];
    }

    /** @deprecated Use NestingJob::addStock(). */
    public function addStockFormat(float $width, float $height, int $stock): void
    {
        $this->requireLegacy();
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Stock format dimensions must be positive.');
        }
        if ($stock < 1) {
            throw new \InvalidArgumentException('Stock must be at least 1.');
        }
        $this->legacyStock[] = [$width, $height, $stock];
    }

    /** @deprecated Use NestingJob::addPiece(). */
    public function addPiece(float $width, float $height, int $qty): void
    {
        $this->requireLegacy();
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Piece dimensions must be positive.');
        }
        if ($qty < 1) {
            throw new \InvalidArgumentException('Piece quantity must be at least 1.');
        }
        $this->legacyPieces[] = [$width, $height, $qty];
    }

    /** @deprecated Use Options\Remnants::defined(). */
    public function addRemnantFormat(float $width, float $height): void
    {
        $this->requireLegacy();
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Remnant format dimensions must be positive.');
        }
        $this->legacyRemnantFormats[] = [$width, $height];
    }

    /**
     * @deprecated Use solve(); this returns NestingResult::toLegacyArray().
     * @return array<string, mixed>
     */
    public function calculate(): array
    {
        $this->requireLegacy();
        $o = $this->legacyOptions;

        $grain = ['none' => Grain::NONE, 'horizontal' => Grain::ALONG_WIDTH, 'vertical' => Grain::ALONG_HEIGHT][$o['grainDirection']];

        switch ($o['formatStrategy']) {
            case self::STRATEGY_MIN_CUTS:
                $objective = Objective::minCuts($o['cutCostM2']);
                break;
            case self::STRATEGY_BEST_REMNANT:
                $objective = Objective::bestRemnant();
                break;
            default:
                $objective = Objective::minWaste();
        }
        $search = $o['formatStrategy'] === self::STRATEGY_SMALLEST_FIT
            ? Search::singlePass()
            : new Search($o['restarts'], $o['seed'], $o['timeBudgetMs']);

        switch ($o['remnantMode']) {
            case self::REMNANT_AUTO:
                $remnants = Remnants::auto($o['minRemnantSide']);
                break;
            case self::REMNANT_DEFINED:
                $remnants = $this->legacyRemnantFormats !== []
                    ? Remnants::defined($this->legacyRemnantFormats)
                    : Remnants::none();
                break;
            default:
                $remnants = Remnants::none();
        }

        $job = new NestingJob(new Material($o['kerf'], $grain), $objective, $search, $remnants);
        foreach ($this->legacyStock as [$w, $h, $stock]) {
            $job->addStock($w, $h, $stock);
        }
        foreach ($this->legacyPieces as [$w, $h, $qty]) {
            $job->addPiece($w, $h, $qty);
        }

        $result = $this->solve($job);
        $result->objective = $o['formatStrategy'];   // 1.x reported the strategy name
        $this->lastResult  = $result;

        return $result->toLegacyArray();
    }

    /**
     * @deprecated Use Render\SvgRenderer with NestingResult::$sheets.
     * @throws \OutOfRangeException If the sheet index does not exist.
     */
    public function toSvg(int $sheetIndex): string
    {
        if ($this->lastResult === null || !isset($this->lastResult->sheets[$sheetIndex])) {
            throw new \OutOfRangeException("Sheet index {$sheetIndex} does not exist.");
        }

        return (new SvgRenderer(SvgRenderer::LABELS_CS))->render($this->lastResult->sheets[$sheetIndex]);
    }

    private function requireLegacy(): void
    {
        if ($this->legacyOptions === null) {
            throw new \LogicException(
                'The 1.x methods need the 1.x constructor arguments; use NestingJob and solve() instead.'
            );
        }
    }
}
