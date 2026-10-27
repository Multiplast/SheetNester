<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

use Multiplast\SheetNester\Internal\CutCalculator;
use Multiplast\SheetNester\Internal\FormatReducer;
use Multiplast\SheetNester\Internal\GuillotinePacker;
use Multiplast\SheetNester\Internal\Objective;
use Multiplast\SheetNester\Internal\Piece;
use Multiplast\SheetNester\Internal\RemnantFinder;
use Multiplast\SheetNester\Internal\ResultBuilder;
use Multiplast\SheetNester\Internal\SheetEliminator;
use Multiplast\SheetNester\Internal\Solver;
use Multiplast\SheetNester\Internal\StockFormat;
use Multiplast\SheetNester\Render\SvgRenderer;

/**
 * SheetNester – 2D bin packing for plastic sheet materials (polycarbonate etc.)
 *
 * Uses the Guillotine Cut algorithm with Best Area Fit (BAF) heuristic
 * and First Fit Decreasing (FFD) piece ordering. New-sheet formats are chosen
 * by a capped lookahead utilisation score; after each pass the engine tries
 * to eliminate the worst-utilised sheet (repack into the others) and to
 * shrink sheet formats (format reduction).
 *
 * Strategies (same greedy engine; multi-pass ones differ in the tie-break):
 *  - 'smallest_fit' : Single FFD pass. restarts/seed/timeBudget unused.
 *  - 'min_waste'    : Multi-pass – canonical FFD, a deterministic portfolio
 *                     (max side / width / height / perimeter), then `restarts`
 *                     random shuffles (duplicates skipped). Best result wins:
 *                     fewest unplaced, then least material (m²).
 *  - 'min_cuts'     : Saw-time optimisation. Minimises a weighted score
 *                     material_m2 + cutCostM2 × cut_count, i.e. it MAY trade
 *                     a little material for noticeably fewer saw passes –
 *                     the cutCostM2 parameter sets the exchange rate.
 *                     Ties broken by total cut length (mm).
 *  - 'best_remnant' : Like min_waste; among material-tied results prefers the
 *                     one whose largest single usable rectangle is biggest
 *                     (one big offcut back to stock beats scattered waste).
 *
 * Features:
 *  - Multiple stock formats with individual inventory limits
 *  - Grain direction enforcement (prevents rotation when required)
 *  - Kerf (blade width) compensation
 *  - SVG visualisation per sheet
 *  - Unplaced piece tracking
 *  - Calculation time reporting
 *
 * This class is the public facade: it validates and collects the input and
 * wires the engine together. The work is done by the classes in Internal\
 * (GuillotinePacker, SheetEliminator, FormatReducer, Solver, CutCalculator,
 * RemnantFinder, ResultBuilder) and the preview by Render\SvgRenderer.
 */
class SheetNester
{
    public const STRATEGY_SMALLEST_FIT    = 'smallest_fit';
    public const STRATEGY_MIN_WASTE       = 'min_waste';
    public const STRATEGY_MIN_CUTS        = 'min_cuts';
    public const STRATEGY_BEST_REMNANT    = 'best_remnant';

    /**
     * Number of random piece-order restarts for STRATEGY_MIN_WASTE (in addition
     * to the canonical FFD pass). Higher = better quality, longer runtime.
     */
    public const MIN_WASTE_RESTARTS = 20;

    public const REMNANT_NONE    = 'none';
    public const REMNANT_AUTO    = 'auto';
    public const REMNANT_DEFINED = 'defined';

    /** @var StockFormat[] */
    private array $stockFormats = [];

    /** @var Piece[] */
    private array $pieces = [];

    /** @var array<string, mixed> – result of the last calculate(), used by toSvg() */
    private array $lastResult = [];

    private float  $kerf;
    private string $grainDirection;
    private string $formatStrategy;
    private int    $restarts;
    private ?int   $seed;
    private ?float $timeBudgetMs;
    private string $remnantMode;
    private float  $minRemnantSide;
    private float  $cutCostM2;

    /** @var array<int, array{float, float}> – remnant formats for REMNANT_DEFINED */
    private array $remnantFormats = [];

    /**
     * @param float      $kerf           Blade kerf width in mm (added to each piece footprint).
     * @param string     $grainDirection Grain / channel direction: 'horizontal', 'vertical', or 'none'.
     * @param string     $formatStrategy Strategy: 'smallest_fit' (single pass), 'min_waste',
     *                                   'min_cuts' (material tie → shortest cut length), or
     *                                   'best_remnant' (material tie → biggest single offcut).
     * @param int        $restarts       Number of random piece-order restarts for the multi-pass
     *                                   strategies (min_waste / min_cuts / best_remnant),
     *                                   in addition to the canonical FFD pass.
     *                                   0 = FFD only, higher = better quality / longer runtime.
     *                                   Defaults to MIN_WASTE_RESTARTS constant (20).
     * @param int|null   $seed           Random seed for reproducible results. null = random each run.
     * @param float|null $timeBudgetMs   Soft time limit for calculate() in milliseconds.
     *                                   The canonical FFD pass always runs; further restarts are
     *                                   skipped once the budget is exhausted (best-so-far is kept).
     *                                   null = no limit. Important on hosting without set_time_limit.
     * @param string     $remnantMode    Usable-remnant marking: 'none' (default, no computation),
     *                                   'auto' (mark largest empty rectangles), or 'defined'
     *                                   (fit formats registered via addRemnantFormat()).
     * @param float      $minRemnantSide Minimum shorter side (mm) of a rectangle to count as
     *                                   a usable remnant in 'auto' mode.
     * @param float      $cutCostM2      STRATEGY_MIN_CUTS only: material-equivalent value of one
     *                                   saw pass, in m². The strategy minimises
     *                                   material_m2 + cutCostM2 × cut_count, so e.g. 0.05 means
     *                                   "one saved cut is worth 0.05 m² of material".
     *                                   0 = never trade material, only break ties by cuts.
     */
    public function __construct(
        float  $kerf,
        string $grainDirection = 'none',
        string $formatStrategy = self::STRATEGY_SMALLEST_FIT,
        int    $restarts       = self::MIN_WASTE_RESTARTS,
        ?int   $seed           = null,
        ?float $timeBudgetMs   = null,
        string $remnantMode    = self::REMNANT_NONE,
        float  $minRemnantSide = 200.0,
        float  $cutCostM2      = 0.05,
    ) {
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
        $this->kerf           = $kerf;
        $this->grainDirection = $grainDirection;
        $this->formatStrategy = $formatStrategy;
        $this->restarts       = $restarts;
        $this->seed           = $seed;
        $this->timeBudgetMs   = $timeBudgetMs;
        $this->remnantMode    = $remnantMode;
        $this->minRemnantSide = $minRemnantSide;
        $this->cutCostM2      = $cutCostM2;
    }

    /**
     * Registers an available stock sheet format with a given inventory.
     *
     * @param float $width  Sheet width in mm.
     * @param float $height Sheet height in mm.
     * @param int   $stock  Maximum number of sheets available.
     */
    public function addStockFormat(float $width, float $height, int $stock): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Stock format dimensions must be positive.');
        }
        if ($stock < 1) {
            throw new \InvalidArgumentException('Stock must be at least 1.');
        }
        $this->stockFormats[] = new StockFormat($width, $height, $stock);
    }

    /**
     * Adds a piece (or multiple identical pieces) to the nesting job.
     *
     * @param float $width  Piece width in mm.
     * @param float $height Piece height in mm.
     * @param int   $qty    Number of identical pieces required.
     */
    public function addPiece(float $width, float $height, int $qty): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Piece dimensions must be positive.');
        }
        if ($qty < 1) {
            throw new \InvalidArgumentException('Piece quantity must be at least 1.');
        }
        $this->pieces[] = new Piece($width, $height, $qty);
    }

    /**
     * Registers a possible remnant format for REMNANT_DEFINED mode.
     * The engine greedily fits these formats (largest area first, each
     * repeatedly while it fits) into the free area of every sheet.
     *
     * @param float $width  Remnant width in mm.
     * @param float $height Remnant height in mm.
     */
    public function addRemnantFormat(float $width, float $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Remnant format dimensions must be positive.');
        }
        $this->remnantFormats[] = [$width, $height];
    }

    /**
     * Runs the nesting algorithm and returns a structured result array.
     *
     * Pipeline: expand + FFD-sort the pieces → Solver (one or many orderings of
     * pack → eliminate sheets → reduce formats → build result) → order the
     * sheets largest format first → attach usable remnants (winning sheets
     * only, never inside the restart loop).
     *
     * @return array{
     *   sheets: array,
     *   stock_usage: array,
     *   unplaced_pieces: array,
     *   total_sheets: int,
     *   total_waste_pct: float,
     *   utilization_pct: float,
     *   total_material_m2: float,
     *   total_cuts_count: int,
     *   total_cuts_mm: float,
     *   total_cuts_horiz_mm: float,
     *   total_cuts_vert_mm: float,
     *   total_seal_mm: float,
     *   strategy: string,
     *   permutations_tested: int,
     *   time_budget_hit: bool,
     *   calculation_time_ms: float
     * }  plus total_remnant_m2 / total_remnant_pct (and sheets[].remnants,
     *     sheets[].remnant_area_m2) when remnantMode is not 'none'.
     */
    public function calculate(): array
    {
        $startTime     = microtime(true);
        $allPieces     = $this->expandAndSort();
        $allowRotation = $this->grainDirection === 'none';

        $packer   = new GuillotinePacker($this->kerf, $allowRotation, $this->stockFormats);
        $remnants = new RemnantFinder($this->kerf, $allowRotation, $this->minRemnantSide, $this->remnantFormats);
        $builder  = new ResultBuilder($this->stockFormats, $this->kerf, new CutCalculator());
        $solver   = new Solver(
            $packer,
            new SheetEliminator($packer),
            new FormatReducer($this->stockFormats, $this->kerf),
            $builder,
            new Objective($this->formatStrategy, $this->cutCostM2, $remnants),
            $this->formatStrategy,
            $this->restarts,
            $this->seed,
            $this->timeBudgetMs,
        );

        [$result, $sheets] = $solver->solve($allPieces);

        // Order the final sheets largest format first (grouping identical
        // formats) so the operator can pull one format from stock at a time.
        $result = $builder->sortSheetsBySize($sheets, $result);

        // Usable remnants – computed once, for the winning sheets only.
        if ($this->remnantMode !== self::REMNANT_NONE) {
            $result = $builder->attachRemnants($result, $sheets, $remnants, $this->remnantMode);
        }

        $result['strategy']            = $this->formatStrategy;
        $result['calculation_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

        $this->lastResult = $result;
        return $result;
    }

    /**
     * Generates an SVG visualisation of the specified sheet of the last calculate().
     *
     * @param int $sheetIndex Zero-based index of the sheet to visualise.
     * @return string SVG markup.
     * @throws \OutOfRangeException If the sheet index does not exist.
     */
    public function toSvg(int $sheetIndex): string
    {
        if (!isset($this->lastResult['sheets'][$sheetIndex])) {
            throw new \OutOfRangeException("Sheet index {$sheetIndex} does not exist.");
        }

        return (new SvgRenderer())->render($this->lastResult['sheets'][$sheetIndex], $sheetIndex);
    }

    /**
     * Expands all Piece objects into individual [width, height] pairs
     * and sorts them by area descending (FFD).
     *
     * @return array<int, array{float, float}>
     */
    private function expandAndSort(): array
    {
        $all = [];
        foreach ($this->pieces as $piece) {
            for ($i = 0; $i < $piece->qty; $i++) {
                $all[] = [$piece->width, $piece->height];
            }
        }
        usort($all, fn($a, $b) => ($b[0] * $b[1]) <=> ($a[0] * $a[1]));
        return $all;
    }
}
