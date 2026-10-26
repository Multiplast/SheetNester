<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

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

    /** Safety cap on marked remnants per sheet. */
    private const MAX_REMNANTS_PER_SHEET = 10;

    /**
     * Deterministic complexity cap for the whole remnant phase, in grid-cell
     * tests (~1 s CPU). Deliberately NOT wall-clock: seeded runs must stay
     * byte-reproducible, and the remnant phase runs outside timeBudgetMs.
     * On pathological inputs (hundreds of footprints per sheet) marking just
     * stops early – the feature is advisory.
     */
    private const REMNANT_WORK_BUDGET = 30_000_000;

    /**
     * How many upcoming pieces scoreLookahead() simulates when choosing the
     * format for a new sheet. This is a PROTECTIVE bound for pathologically
     * large inputs, not a quality knob: benchmarking showed that a low cap
     * (25) changes format choices and regresses material usage on real-sized
     * orders, so the value is set high enough to never bind for typical
     * orders (tens to low hundreds of pieces).
     */
    private const LOOKAHEAD_PIECES = 250;

    /** @var StockFormat[] */
    private array $stockFormats = [];

    /** @var Piece[] */
    private array $pieces = [];

    /** @var Sheet[] – best result sheets, populated after calculate() */
    private array $sheets = [];

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
     * For strategy 'min_waste': tries all permutations of stock format priority
     * and returns the result with the lowest total waste percentage.
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
     *   strategy: string,
     *   permutations_tested: int,
     *   time_budget_hit: bool,
     *   calculation_time_ms: float
     * }
     */
    public function calculate(): array
    {
        $startTime = microtime(true);
        $allPieces = $this->expandAndSort();

        if ($this->formatStrategy === self::STRATEGY_SMALLEST_FIT) {
            $result = $this->calculateSmallestFit($allPieces);
        } else {
            $result = $this->calculateMultiPass($allPieces);
        }

        // Order the final sheets largest format first (grouping identical
        // formats) so the operator can pull one format from stock at a time.
        $result = $this->sortSheetsBySize($result);

        // Usable remnants – computed once, for the winning sheets only
        // (never inside the min_waste restart loop).
        if ($this->remnantMode !== self::REMNANT_NONE) {
            $result = $this->attachRemnants($result);
        }

        $result['strategy']            = $this->formatStrategy;
        $result['calculation_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

        return $result;
    }

    /**
     * Generates an SVG visualisation of the specified sheet.
     *
     * @param int $sheetIndex Zero-based index of the sheet to visualise.
     * @return string SVG markup.
     * @throws \OutOfRangeException If the sheet index does not exist.
     */
    public function toSvg(int $sheetIndex): string
    {
        if (!isset($this->sheets[$sheetIndex])) {
            throw new \OutOfRangeException("Sheet index {$sheetIndex} does not exist.");
        }

        $sheet  = $this->sheets[$sheetIndex];
        $sw     = $sheet->format->width;
        $sh     = $sheet->format->height;

        $scale  = min(900 / $sw, 600 / $sh);
        $svgW   = (int) round($sw * $scale);
        $svgH   = (int) round($sh * $scale);
        $padTop = 30;

        $colors = [
            '#4E9AF1','#F1A94E','#E45C3A','#5DBB63','#9B59B6',
            '#1ABC9C','#E74C3C','#3498DB','#F39C12','#2ECC71',
            '#E67E22','#16A085','#8E44AD','#D35400','#27AE60',
        ];

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
            . 'viewBox="0 0 %d %d" preserveAspectRatio="xMidYMid meet" '
            . 'style="background:#f0f0f0;font-family:Arial,sans-serif;">',
            $svgW,
            $svgH + $padTop,
            $svgW,
            $svgH + $padTop
        );

        $usedPct = $sheet->usedAreaPct($this->kerf);
        $svg .= sprintf(
            '<text x="8" y="20" font-size="13" fill="#333" font-weight="bold">'
            . 'Deska #%d &nbsp; %g × %g mm &nbsp;|&nbsp; Využití: %s%%'
            . '</text>',
            $sheetIndex + 1,
            $sw,
            $sh,
            $usedPct
        );

        $svg .= sprintf(
            '<rect x="0" y="%d" width="%d" height="%d" fill="white" stroke="#999" stroke-width="1.5"/>',
            $padTop, $svgW, $svgH
        );

        foreach ($sheet->pieces as $i => $p) {
            $color = $colors[$i % count($colors)];
            $x     = (int) round($p->x * $scale);
            $y     = (int) round($p->y * $scale) + $padTop;
            $rw    = (int) round($p->width * $scale);
            $rh    = (int) round($p->height * $scale);

            $fill = $color;
            if ($p->rotated) {
                $patId = "hatch{$sheetIndex}_{$i}";
                $svg .= sprintf(
                    '<defs><pattern id="%s" patternUnits="userSpaceOnUse" width="8" height="8">'
                    . '<rect width="8" height="8" fill="%s"/>'
                    . '<line x1="0" y1="8" x2="8" y2="0" stroke="rgba(0,0,0,0.18)" stroke-width="1.5"/>'
                    . '</pattern></defs>',
                    $patId, $color
                );
                $fill = "url(#{$patId})";
            }

            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" fill="%s" '
                . 'stroke="#fff" stroke-width="1.5" opacity="0.88"/>',
                $x, $y, $rw, $rh, $fill
            );

            $label    = sprintf('%g×%g', $p->width, $p->height) . ($p->rotated ? ' ↺' : '');
            $fontSize = max(8, min(13, (int) ($rw / max(1, strlen($label)) * 1.6)));
            if ($rw > 20 && $rh > 14) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-size="%d" fill="#fff" text-anchor="middle" '
                    . 'dominant-baseline="middle" font-weight="bold" '
                    . 'style="text-shadow:0 1px 2px rgba(0,0,0,.4)">%s</text>',
                    $x + (int) ($rw / 2),
                    $y + (int) ($rh / 2),
                    $fontSize,
                    htmlspecialchars($label)
                );
            }
        }

        // Usable remnants – grey like waste, dashed green outline + dimensions
        foreach ($sheet->remnants as $r) {
            $x  = (int) round($r->x * $scale);
            $y  = (int) round($r->y * $scale) + $padTop;
            $rw = (int) round($r->width * $scale);
            $rh = (int) round($r->height * $scale);

            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" fill="rgba(46,125,50,0.08)" '
                . 'stroke="#2e7d32" stroke-width="1.5" stroke-dasharray="6,4"/>',
                $x, $y, $rw, $rh
            );

            $label    = sprintf('zbytek %g×%g', $r->width, $r->height);
            $fontSize = max(8, min(13, (int) ($rw / max(1, strlen($label)) * 1.6)));
            if ($rw > 30 && $rh > 14) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-size="%d" fill="#2e7d32" text-anchor="middle" '
                    . 'dominant-baseline="middle" font-weight="bold">%s</text>',
                    $x + (int) ($rw / 2),
                    $y + (int) ($rh / 2),
                    $fontSize,
                    htmlspecialchars($label)
                );
            }
        }

        if (array_filter($sheet->pieces, fn($p) => $p->rotated)) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-size="10" fill="#666">↺ = otočeno o 90°</text>',
                $svgW - 120,
                $svgH + $padTop - 5
            );
        }

        $svg .= '</svg>';
        return $svg;
    }

    // =========================================================================
    // Strategy: smallest_fit
    // =========================================================================

    /**
     * Runs a single nesting pass in FFD order (the shared greedy engine with
     * lookahead format selection – formats are scored by estimated utilisation,
     * ties prefer the smaller format). restarts/seed/timeBudget are not used
     * by this strategy.
     *
     * @param  array<int, array{float, float}> $allPieces
     */
    private function calculateSmallestFit(array $allPieces): array
    {
        [$sheets, $unplaced] = $this->runNesting($allPieces);
        $this->tryEliminateSheets($sheets);
        $this->applyFormatReduction($sheets);
        $this->sheets = $sheets;

        $result = $this->buildResult($sheets, $unplaced);
        $result['permutations_tested'] = 1;
        $result['time_budget_hit']     = false;
        return $result;
    }

    // =========================================================================
    // Strategies: min_waste / min_cuts / best_remnant (multi-pass)
    // =========================================================================

    /**
     * Multi-pass pipeline shared by min_waste, min_cuts and best_remnant –
     * repeated over multiple piece orderings.
     *
     * Orderings tested (duplicates skipped via a canonical key):
     *   pass 0        – canonical FFD (area descending, always runs),
     *   passes 1..4   – deterministic portfolio: max side / width / height /
     *                   perimeter descending (cheap, often beats random),
     *   further       – `restarts` random shuffles (seeded → reproducible).
     *
     * For each ordering:
     *   Step 1 – Greedy nesting (Best Area Fit / Guillotine Cut).
     *            When a new sheet must be opened, the format with the best
     *            lookahead utilisation score is chosen (scoreLookahead).
     *   Step 2 – Sheet elimination: try to repack the pieces of the worst-
     *            utilised sheet into the free areas of the other sheets;
     *            success removes a whole sheet (tryEliminateSheets).
     *   Step 3 – Post-process format reduction: for every sheet check whether
     *            a SMALLER available format would still hold all its pieces.
     *   Step 4 – Evaluate: primary = fewest unplaced pieces, secondary =
     *            lowest primary cost (primaryCost: material m², for min_cuts
     *            material + cutCostM2 × cut count); on a primary-cost tie
     *            min_cuts / best_remnant apply their tie-break (tiebreakScore,
     *            computed lazily only for tied candidates).
     *
     * The best result across all orderings is kept.
     *
     * @param  array<int, array{float, float}> $allPieces  FFD-sorted expanded pieces.
     */
    private function calculateMultiPass(array $allPieces): array
    {
        $bestResult      = null;
        $bestSheets      = [];
        $bestPrimary     = PHP_FLOAT_MAX;
        $bestTiebreak    = null;
        $tested          = 0;
        $budgetHit       = false;
        $deadline        = $this->timeBudgetMs !== null
            ? microtime(true) + $this->timeBudgetMs / 1000
            : null;

        // If a seed is set, initialise the RNG once so results are reproducible.
        if ($this->seed !== null) {
            mt_srand($this->seed);
        }

        // Pass 0 = canonical FFD, then deterministic portfolio, then shuffles.
        // Identical orderings (common with many same-size pieces) run only once.
        $orderings = [$allPieces];
        $seen      = [$this->orderingKey($allPieces) => true];

        foreach ($this->deterministicOrderings($allPieces) as $ordered) {
            $key = $this->orderingKey($ordered);
            if (!isset($seen[$key])) {
                $seen[$key]  = true;
                $orderings[] = $ordered;
            }
        }
        for ($i = 0; $i < $this->restarts; $i++) {
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

            // Step 1 – Greedy nesting with lookahead format selection
            [$sheets, $unplaced] = $this->runNesting($pieces);

            // Step 2 – Try to eliminate the worst-utilised sheet(s)
            $this->tryEliminateSheets($sheets);

            // Step 3 – Post-process: shrink sheet formats where possible
            $this->applyFormatReduction($sheets);

            $result = $this->buildResult($sheets, $unplaced);
            $tested++;

            $unplacedCount  = array_sum(array_column($unplaced, 'qty'));
            $bestUnplaced   = $bestResult
                ? array_sum(array_column($bestResult['unplaced_pieces'], 'qty'))
                : PHP_INT_MAX;

            // Step 4 – Compare: fewer unplaced wins; tie → lower primary cost
            // (min_waste/best_remnant: material m²; min_cuts: material m² +
            // cutCostM2 × cut count); primary tie → strategy tie-break
            // (min_cuts: shorter cut length, best_remnant: bigger offcut).
            $candPrimary  = $this->primaryCost($result);
            $isBetter     = false;
            $candTiebreak = null;

            if ($unplacedCount < $bestUnplaced) {
                $isBetter = true;
            } elseif ($unplacedCount === $bestUnplaced) {
                if ($candPrimary < $bestPrimary - 1e-9) {
                    $isBetter = true;
                } elseif ($this->formatStrategy !== self::STRATEGY_MIN_WASTE
                    && abs($candPrimary - $bestPrimary) <= 1e-9
                ) {
                    // Lazy: tie-break scores are computed only for tied candidates
                    // (and once for the current best, then cached).
                    $candTiebreak = $this->tiebreakScore($result, $sheets);
                    if ($bestTiebreak === null) {
                        $bestTiebreak = $this->tiebreakScore($bestResult, $bestSheets);
                    }
                    if ($candTiebreak < $bestTiebreak - 1e-9) {
                        $isBetter = true;
                    }
                }
            }

            if ($isBetter) {
                $bestResult     = $result;
                $bestSheets     = $sheets;
                $bestPrimary    = $candPrimary;
                // Known only when the win came through the tie-break branch;
                // otherwise recomputed lazily on the next primary-cost tie.
                $bestTiebreak   = $candTiebreak;
            }
        }

        $this->sheets = $bestSheets;
        $bestResult['permutations_tested'] = $tested;
        $bestResult['time_budget_hit']     = $budgetHit;
        return $bestResult;
    }

    // =========================================================================
    // Core nesting engine (shared by both strategies)
    // =========================================================================

    /**
     * Runs one complete greedy nesting pass.
     *
     * When a new sheet must be opened, nestOnNew uses a lookahead score to
     * choose the best-fitting format from all available formats (sorted by
     * utilisation ratio; ties broken by area ascending = prefer smaller).
     *
     * Does NOT mutate $this->sheets or the original $this->stockFormats.
     *
     * @param  array<int, array{float, float}> $allPieces
     * @return array{Sheet[], array}           [sheets, unplaced]
     */
    private function runNesting(array $allPieces): array
    {
        // Deep-copy format inventory so each run is fully independent
        $formats = [];
        foreach ($this->stockFormats as $fmt) {
            $copy            = new StockFormat($fmt->width, $fmt->height, $fmt->stock);
            $copy->remaining = $fmt->stock;
            $formats[]       = $copy;
        }

        $sheets   = [];
        $unplaced = [];

        foreach ($allPieces as $idx => [$pw, $ph]) {
            if (!$this->nestOnExisting($sheets, $pw, $ph)) {
                if (!$this->nestOnNew($sheets, $formats, $pw, $ph, $allPieces, $idx + 1)) {
                    $reason = $this->diagnoseFailure($pw, $ph, $formats);
                    $found  = false;
                    foreach ($unplaced as &$u) {
                        if ($u['width'] === $pw && $u['height'] === $ph) {
                            $u['qty']++;
                            $found = true;
                            break;
                        }
                    }
                    unset($u);
                    if (!$found) {
                        $unplaced[] = [
                            'width'  => $pw,
                            'height' => $ph,
                            'qty'    => 1,
                            'reason' => $reason,
                        ];
                    }
                }
            }
        }

        return [$sheets, $unplaced];
    }

    /**
     * Attempts to place a piece on one of the already-opened sheets (Best Area Fit).
     *
     * @param Sheet[] $sheets
     */
    private function nestOnExisting(array &$sheets, float $pw, float $ph): bool
    {
        $bestSheet   = null;
        $bestRect    = null;
        $bestArea    = PHP_FLOAT_MAX;
        $bestRotated = false;

        foreach ($sheets as $sheet) {
            [$rect, $rotated] = $this->findBestRect($sheet, $pw, $ph);
            if ($rect !== null && $rect->area() < $bestArea) {
                $bestArea    = $rect->area();
                $bestSheet   = $sheet;
                $bestRect    = $rect;
                $bestRotated = $rotated;
            }
        }

        if ($bestSheet !== null && $bestRect !== null) {
            $this->placePiece($bestSheet, $bestRect, $pw, $ph, $bestRotated);
            return true;
        }

        return false;
    }

    /**
     * Opens a new sheet for the given piece.
     *
     * For each fitting format a lookahead simulation estimates how well the
     * current piece + remaining pieces would fill it. The format with the
     * highest utilisation ratio (placed_area / format_area) is selected.
     * Ties (within 0.1 %) are broken by area ascending (prefer smaller sheet).
     *
     * Because PHP arrays of objects share the same instances, modifying
     * $format->remaining on a sorted copy also updates the original $formats.
     *
     * @param Sheet[]                         $sheets
     * @param StockFormat[]                   $formats   Mutable inventory copies.
     * @param array<int, array{float, float}> $allPieces All pieces of the pass.
     * @param int                             $fromIdx   Index of the first not-yet-processed piece.
     */
    private function nestOnNew(array &$sheets, array &$formats, float $pw, float $ph, array $allPieces, int $fromIdx): bool
    {
        $candidates = [];

        foreach ($formats as $format) {
            if ($format->remaining <= 0) {
                continue;
            }

            $rotated    = false;
            $fitsNormal = ($pw <= $format->width) && ($ph <= $format->height);

            if (!$fitsNormal) {
                if ($this->grainDirection !== 'none') {
                    continue;
                }
                if (($ph <= $format->width) && ($pw <= $format->height)) {
                    $rotated = true;
                } else {
                    continue;
                }
            }

            $score = $this->scoreLookahead($format, $pw, $ph, $rotated, $allPieces, $fromIdx);

            $candidates[] = ['format' => $format, 'rotated' => $rotated, 'score' => $score];
        }

        if (empty($candidates)) {
            return false;
        }

        // Best utilisation wins; within 0.1 % prefer the smaller format
        usort($candidates, function ($a, $b) {
            if (abs($a['score'] - $b['score']) > 0.001) {
                return $b['score'] <=> $a['score'];
            }
            return $a['format']->area() <=> $b['format']->area();
        });

        $best   = $candidates[0];
        $format = $best['format'];

        $format->remaining--;
        $sheet    = new Sheet(count($sheets), $format);
        $sheets[] = $sheet;

        [$rect, $actualRotated] = $this->findBestRect($sheet, $pw, $ph);
        if ($rect === null) {
            array_pop($sheets);
            $format->remaining++;
            return false;
        }

        $this->placePiece($sheet, $rect, $pw, $ph, $actualRotated ?? $best['rotated']);
        return true;
    }

    /**
     * Simulates filling a fresh copy of $format with the current piece and
     * up to LOOKAHEAD_PIECES upcoming pieces (greedy, no commitment).
     * Iterates $allPieces from $fromIdx directly – no array copying in the
     * hot loop.
     *
     * Returns placed_area / format_area  (utilisation ratio, 0–1).
     *
     * @param array<int, array{float, float}> $allPieces
     */
    private function scoreLookahead(
        StockFormat $format,
        float       $pw,
        float       $ph,
        bool        $rotated,
        array       $allPieces,
        int         $fromIdx
    ): float {
        $tmp = new Sheet(0, $format);

        [$rect, $r] = $this->findBestRect($tmp, $pw, $ph);
        if ($rect === null) {
            return 0.0;
        }
        $this->placePiece($tmp, $rect, $pw, $ph, $r ?? $rotated);
        $placed = $pw * $ph;

        $limit = min(count($allPieces), $fromIdx + self::LOOKAHEAD_PIECES);
        for ($i = $fromIdx; $i < $limit; $i++) {
            [$rpw, $rph] = $allPieces[$i];
            [$rect, $rr] = $this->findBestRect($tmp, $rpw, $rph);
            if ($rect !== null) {
                $this->placePiece($tmp, $rect, $rpw, $rph, $rr);
                $placed += $rpw * $rph;
            }
        }

        return $format->area() > 0 ? $placed / $format->area() : 0.0;
    }

    /**
     * Post-processing step: tries to ELIMINATE whole sheets. Takes the sheet
     * with the lowest piece-area utilisation, and attempts to repack all its
     * pieces into the free rectangles of the other sheets (largest piece
     * first, Best Area Fit across sheets). On success the sheet is dropped
     * and the attempt repeats – each success saves one whole board.
     *
     * Placements are simulated on deep copies of the target sheets, so a
     * failed attempt leaves everything untouched.
     *
     * MUST run BEFORE applyFormatReduction(): the repacking relies on live
     * freeRects, which reduction leaves stale (they refer to the original,
     * larger format geometry).
     *
     * Note: a piece re-placed here keeps its actual (possibly rotated)
     * dimensions; its `rotated` flag is relative to those, which only affects
     * the display hatch/legend, never the geometry.
     *
     * @param Sheet[] $sheets  Modified in place (re-indexed on elimination).
     */
    private function tryEliminateSheets(array &$sheets): void
    {
        while (count($sheets) >= 2) {

            // Sheet with the lowest utilisation (piece area / format area)
            $worstIdx   = null;
            $worstRatio = PHP_FLOAT_MAX;
            foreach ($sheets as $i => $sheet) {
                $area = $sheet->format->area();
                if ($area <= 0) {
                    continue;
                }
                $pieceArea = 0.0;
                foreach ($sheet->pieces as $p) {
                    $pieceArea += $p->width * $p->height;
                }
                $ratio = $pieceArea / $area;
                if ($ratio < $worstRatio) {
                    $worstRatio = $ratio;
                    $worstIdx   = $i;
                }
            }
            if ($worstIdx === null) {
                return;
            }

            // Simulate on deep copies of all other sheets
            $targets = [];
            foreach ($sheets as $i => $sheet) {
                if ($i !== $worstIdx) {
                    $targets[$i] = $this->cloneSheet($sheet);
                }
            }

            $pieces = $sheets[$worstIdx]->pieces;
            usort($pieces, fn($a, $b) => ($b->width * $b->height) <=> ($a->width * $a->height));

            $allPlaced = true;
            foreach ($pieces as $p) {
                if (!$this->nestOnExisting($targets, $p->width, $p->height)) {
                    $allPlaced = false;
                    break;
                }
            }

            if (!$allPlaced) {
                return;   // worst sheet cannot be dissolved – stop trying
            }

            // Commit: keep the modified copies, drop the eliminated sheet
            $new = [];
            foreach ($sheets as $i => $sheet) {
                if ($i !== $worstIdx) {
                    $new[] = $targets[$i];
                }
            }
            foreach ($new as $idx => $sheet) {
                $sheet->index = $idx;
            }
            $sheets = $new;
        }
    }

    /**
     * Deep copy of a sheet (pieces + freeRects); the StockFormat reference is
     * shared intentionally – elimination never mutates formats, and stock
     * accounting is derived arithmetically from the sheets afterwards.
     */
    private function cloneSheet(Sheet $sheet): Sheet
    {
        $copy = new Sheet($sheet->index, $sheet->format);

        $copy->pieces = [];
        foreach ($sheet->pieces as $p) {
            $copy->pieces[] = new PlacedPiece($p->x, $p->y, $p->width, $p->height, $p->rotated);
        }

        $copy->freeRects = [];
        foreach ($sheet->freeRects as $r) {
            $copy->freeRects[] = new FreeRect($r->x, $r->y, $r->width, $r->height);
        }

        return $copy;
    }

    /**
     * Strategy primary cost – LOWER is better, compared right after the
     * unplaced count:
     *  - min_waste / best_remnant: total material (m²),
     *  - min_cuts: total material (m²) + cutCostM2 × number of saw passes –
     *    the weighted saw-time objective (a saved cut is "worth" cutCostM2
     *    of material).
     */
    private function primaryCost(array $result): float
    {
        if ($this->formatStrategy === self::STRATEGY_MIN_CUTS) {
            return $result['total_material_m2']
                + $this->cutCostM2 * $result['total_cuts_count'];
        }
        return $result['total_material_m2'];
    }

    /**
     * Strategy-specific tie-break score – LOWER is better. Applied only among
     * candidates tied on unplaced count AND primary cost:
     *  - min_cuts:     total internal cut length in mm (shorter saw path),
     *  - best_remnant: negative area (m²) of the largest single empty
     *                  rectangle across all sheets (prefer one big offcut).
     *
     * @param Sheet[] $sheets  Post-reduction sheets of the candidate result.
     */
    private function tiebreakScore(array $result, array $sheets): float
    {
        if ($this->formatStrategy === self::STRATEGY_MIN_CUTS) {
            return (float) $result['total_cuts_mm'];
        }
        return -$this->largestSingleRemnantM2($sheets);
    }

    /**
     * Area (m²) of the largest single empty rectangle across all sheets.
     * Cheap single-shot variant for the best_remnant tie-break: one
     * largestEmptyRect call per sheet, occupied = piece footprints only.
     * Deterministic; REMNANT_WORK_BUDGET guards pathological sheets.
     *
     * @param Sheet[] $sheets
     */
    private function largestSingleRemnantM2(array $sheets): float
    {
        $best   = 0.0;
        $budget = self::REMNANT_WORK_BUDGET;

        foreach ($sheets as $sheet) {
            $occupied = $this->collectOccupiedRects($sheet, []);
            $rect     = $this->largestEmptyRect(
                $occupied,
                $sheet->format->width,
                $sheet->format->height,
                $budget
            );
            if ($rect !== null) {
                $best = max($best, $rect->area() / 1_000_000);
            }
            if ($budget <= 0) {
                break;
            }
        }

        return $best;
    }

    /**
     * Post-processing step: for each sheet check whether a smaller available
     * stock format (with remaining stock > 0) could hold all pieces on that
     * sheet. If yes, reassign the sheet to the smaller format, freeing up the
     * larger format's stock slot and consuming one of the smaller format's.
     *
     * The check is purely dimensional — all placed piece positions are already
     * within the original (larger) sheet, so they are guaranteed to be within
     * the smaller sheet too (since all x+w ≤ smaller.width and y+h ≤ smaller.height).
     *
     * Formats are tried in ascending area order so the smallest possible format
     * is always chosen.
     *
     * @param Sheet[] $sheets  Modified in place.
     */
    private function applyFormatReduction(array &$sheets): void
    {
        // Build a live inventory map keyed by "width:height"
        // reflecting stock consumed by this run so far.
        // We start from the original stock and subtract sheets already assigned.
        $inventory = [];
        foreach ($this->stockFormats as $fmt) {
            $key = $fmt->width . ':' . $fmt->height;
            $inventory[$key] = $fmt->stock;
        }
        foreach ($sheets as $sheet) {
            $key = $sheet->format->width . ':' . $sheet->format->height;
            $inventory[$key]--;
        }

        // Sort formats ascending by area for reduction attempts
        $sortedFormats = $this->stockFormats;
        usort($sortedFormats, fn($a, $b) => $a->area() <=> $b->area());

        foreach ($sheets as $sheet) {
            $currentArea = $sheet->format->area();

            // Find the bounding box of all pieces on this sheet
            $maxX = 0.0;
            $maxY = 0.0;
            foreach ($sheet->pieces as $p) {
                $maxX = max($maxX, $p->x + $p->width  + $this->kerf);
                $maxY = max($maxY, $p->y + $p->height + $this->kerf);
            }

            foreach ($sortedFormats as $candidate) {
                if ($candidate->area() >= $currentArea) {
                    continue; // only consider strictly smaller formats
                }

                $key = $candidate->width . ':' . $candidate->height;
                if (($inventory[$key] ?? 0) <= 0) {
                    continue; // no stock available
                }

                // All pieces must fit within the candidate dimensions
                if ($maxX <= $candidate->width && $maxY <= $candidate->height) {
                    // Release the current format slot, consume the smaller one
                    $oldKey = $sheet->format->width . ':' . $sheet->format->height;
                    $inventory[$oldKey]++;
                    $inventory[$key]--;

                    // Reassign sheet format (freeRects are not used after nesting,
                    // so only the format reference needs updating for reporting)
                    $sheet->format = new StockFormat(
                        $candidate->width,
                        $candidate->height,
                        $candidate->stock
                    );
                    $currentArea = $candidate->area();
                    // Continue trying even smaller formats
                }
            }
        }
    }

    /**
     * Finds the best free rectangle (Best Area Fit) in a sheet for the given piece.
     *
     * @return array{FreeRect|null, bool}  [bestRect, rotated]
     */
    private function findBestRect(Sheet $sheet, float $pw, float $ph): array
    {
        $bestRect    = null;
        $bestArea    = PHP_FLOAT_MAX;
        $bestRotated = false;

        foreach ($sheet->freeRects as $rect) {
            // Fit check uses raw piece dimensions (no kerf).
            // Kerf is only subtracted from remaining free space during guillotine split.
            if ($pw <= $rect->width && $ph <= $rect->height) {
                if ($rect->area() < $bestArea) {
                    $bestArea    = $rect->area();
                    $bestRect    = $rect;
                    $bestRotated = false;
                }
            }
            if ($this->grainDirection === 'none') {
                if ($ph <= $rect->width && $pw <= $rect->height) {
                    if ($rect->area() < $bestArea) {
                        $bestArea    = $rect->area();
                        $bestRect    = $rect;
                        $bestRotated = true;
                    }
                }
            }
        }

        return [$bestRect, $bestRotated];
    }

    /**
     * Places a piece into a free rectangle using Guillotine Cut (Longer Axis Split).
     */
    private function placePiece(Sheet $sheet, FreeRect $usedRect, float $pw, float $ph, bool $rotated): void
    {
        $actualW = $rotated ? $ph : $pw;
        $actualH = $rotated ? $pw : $ph;
        $footW   = $actualW + $this->kerf;
        $footH   = $actualH + $this->kerf;

        $sheet->pieces[] = new PlacedPiece($usedRect->x, $usedRect->y, $actualW, $actualH, $rotated);

        $sheet->freeRects = array_values(array_filter(
            $sheet->freeRects,
            fn(FreeRect $r) => $r !== $usedRect
        ));

        $rightW  = $usedRect->width  - $footW;
        $bottomH = $usedRect->height - $footH;

        // The primary cut runs the full length of the used rect and consumes its
        // kerf strip entirely; the secondary free rect therefore spans only the
        // piece's actual dimension, never the primary cut's kerf strip.
        if ($rightW >= $bottomH) {
            if ($rightW > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x + $footW, $usedRect->y, $rightW, $usedRect->height
                );
            }
            if ($bottomH > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x, $usedRect->y + $footH, $actualW, $bottomH
                );
            }
        } else {
            if ($bottomH > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x, $usedRect->y + $footH, $usedRect->width, $bottomH
                );
            }
            if ($rightW > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x + $footW, $usedRect->y, $rightW, $actualH
                );
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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

    /**
     * Deterministic ordering portfolio for min_waste: a handful of sensible
     * sort keys often beats hundreds of random shuffles. usort is stable
     * (PHP 8+), so identical dimensions stay adjacent and runs reproduce.
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

    /**
     * Determines why a piece could not be placed given a specific format list.
     *
     * @param StockFormat[] $formats
     */
    private function diagnoseFailure(float $pw, float $ph, array $formats): string
    {
        // Dimensional fit is checked first, regardless of stock: a piece that
        // fits some (exhausted) format failed because of stock, not size.
        foreach ($formats as $fmt) {
            $fitsNormal  = ($pw <= $fmt->width)  && ($ph <= $fmt->height);
            $fitsRotated = ($ph <= $fmt->width)  && ($pw <= $fmt->height);
            if ($fitsNormal || ($this->grainDirection === 'none' && $fitsRotated)) {
                return 'Nedostatek skladových zásob.';
            }
        }
        return 'Kus je větší než všechny dostupné formáty desek.';
    }

    /**
     * Assembles the public result array from a completed nesting run.
     *
     * @param Sheet[] $sheets
     * @param array   $unplaced
     */
    private function buildResult(array $sheets, array $unplaced): array
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
            $cuts = $this->calculateCuts($sheet);
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
        // NOT from the mutated per-run format copies: after applyFormatReduction
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
     * Calculates internal cut lengths and seal length for a single sheet.
     *
     * Cut algorithm:
     *   1. Collect all piece edges as axis-aligned segments (horizontal / vertical).
     *   2. Discard segments on the sheet border (those are edges, not cuts).
     *   3. Merge collinear overlapping/touching segments to avoid double-counting
     *      shared edges between adjacent pieces.
     *   4. Return merged segment list + totals.
     *
     * Seal length = sum of all piece HEIGHTS × 2.
     * For vertical-grain hollow polycarbonate the channels run along the height
     * axis — the open channel ends that need sealing strips are on the left and
     * right edges of each piece (length = height of piece, two sides per piece).
     *
     * @return array{
     *   horizontal_mm: float,
     *   vertical_mm: float,
     *   total_mm: float,
     *   seal_mm: float,
     *   cuts_horizontal: array,
     *   cuts_vertical: array
     * }
     */
    private function calculateCuts(Sheet $sheet): array
    {
        $sw = $sheet->format->width;
        $sh = $sheet->format->height;

        // Horizontal segments keyed by Y coordinate → list of [x1, x2]
        // Vertical segments   keyed by X coordinate → list of [y1, y2]
        $horizSegs = [];
        $vertSegs  = [];
        $sealMm    = 0.0;

        foreach ($sheet->pieces as $p) {
            $x1 = $p->x;
            $y1 = $p->y;
            $x2 = $p->x + $p->width;
            $y2 = $p->y + $p->height;

            // Seal: left + right vertical edge of each piece (height × 2).
            // For vertical-grain panels the channels open on the left/right sides.
            $sealMm += $p->height * 2;

            // Horizontal edges (top and bottom of piece)
            foreach ([$y1, $y2] as $y) {
                if ($y <= 0.0 || $y >= $sh) {
                    continue; // sheet border — not a cut
                }
                $horizSegs[(string) $y][] = [$x1, $x2];
            }

            // Vertical edges (left and right of piece)
            foreach ([$x1, $x2] as $x) {
                if ($x <= 0.0 || $x >= $sw) {
                    continue; // sheet border — not a cut
                }
                $vertSegs[(string) $x][] = [$y1, $y2];
            }
        }

        [$horizMm, $horizList] = $this->mergeSegments($horizSegs, 'horizontal');
        [$vertMm,  $vertList]  = $this->mergeSegments($vertSegs,  'vertical');

        return [
            'horizontal_mm'   => round($horizMm, 1),
            'vertical_mm'     => round($vertMm,  1),
            'total_mm'        => round($horizMm + $vertMm, 1),
            'seal_mm'         => round($sealMm, 1),
            'cuts_horizontal' => $horizList,
            'cuts_vertical'   => $vertList,
        ];
    }

    /**
     * Merges overlapping/touching collinear segments, returns [totalLength, segmentList].
     *
     * Each entry in segmentList:
     *   horizontal: ['y' => float, 'x_start' => float, 'x_end' => float, 'length' => float]
     *   vertical:   ['x' => float, 'y_start' => float, 'y_end' => float, 'length' => float]
     *
     * @param  array<string, array<int, array{float, float}>> $segsByCoord
     * @param  string                                          $axis  'horizontal'|'vertical'
     * @return array{float, array}
     */
    private function mergeSegments(array $segsByCoord, string $axis): array
    {
        $total = 0.0;
        $list  = [];

        foreach ($segsByCoord as $coordStr => $segs) {
            $coord = (float) $coordStr;
            usort($segs, fn($a, $b) => $a[0] <=> $b[0]);

            $merged = [];
            foreach ($segs as [$start, $end]) {
                if (empty($merged)) {
                    $merged[] = [$start, $end];
                    continue;
                }
                $last = &$merged[count($merged) - 1];
                if ($start <= $last[1]) {
                    $last[1] = max($last[1], $end);
                } else {
                    $merged[] = [$start, $end];
                }
            }

            foreach ($merged as [$start, $end]) {
                $len    = round($end - $start, 1);
                $total += $len;
                if ($axis === 'horizontal') {
                    $list[] = [
                        'y'       => $coord,
                        'x_start' => $start,
                        'x_end'   => $end,
                        'length'  => $len,
                    ];
                } else {
                    $list[] = [
                        'x'       => $coord,
                        'y_start' => $start,
                        'y_end'   => $end,
                        'length'  => $len,
                    ];
                }
            }
        }

        // Sort output: horizontal by y then x_start; vertical by x then y_start
        if ($axis === 'horizontal') {
            usort($list, fn($a, $b) => $a['y'] <=> $b['y'] ?: $a['x_start'] <=> $b['x_start']);
        } else {
            usort($list, fn($a, $b) => $a['x'] <=> $b['x'] ?: $a['y_start'] <=> $b['y_start']);
        }

        return [round($total, 1), $list];
    }

    // =========================================================================
    // Usable remnants (advisory)
    // =========================================================================

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
     * Reorders $this->sheets and $result['sheets'] with the SAME permutation
     * so they stay aligned (attachRemnants and toSvg rely on this). usort is
     * stable (PHP 8), so identical formats keep their relative order and the
     * result stays deterministic.
     */
    private function sortSheetsBySize(array $result): array
    {
        $n = count($this->sheets);
        if ($n < 2 || count($result['sheets']) !== $n) {
            return $result;
        }

        $sheets = $this->sheets;
        $order  = range(0, $n - 1);
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

        $this->sheets     = $newSheets;
        $result['sheets'] = $newOut;
        return $result;
    }

    /**
     * Computes usable remnants for the winning sheets and injects them into
     * the result array: sheets[i]['remnants'] (list of {x, y, width, height}),
     * sheets[i]['remnant_area_m2'], total_remnant_m2, total_remnant_pct.
     * Also fills Sheet::$remnants so toSvg() can render them.
     *
     * ADVISORY: marked remnants show the operator / backoffice which leftover
     * rectangles are worth keeping. They do NOT guarantee that the remnant can
     * be separated from the surrounding waste with one sequence of guillotine
     * cuts. Deterministic – no RNG involved, seeded runs stay reproducible.
     */
    private function attachRemnants(array $result): array
    {
        $totalRemnantM2 = 0.0;
        $workBudget     = self::REMNANT_WORK_BUDGET;

        foreach ($this->sheets as $i => $sheet) {
            $sheet->remnants = $this->remnantMode === self::REMNANT_AUTO
                ? $this->findAutoRemnants($sheet, $workBudget)
                : $this->findDefinedRemnants($sheet, $workBudget);

            $remnantsOut = [];
            $areaM2      = 0.0;
            foreach ($sheet->remnants as $r) {
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

    /**
     * AUTO mode: repeatedly finds the largest empty rectangle on the sheet and
     * marks it as a remnant while its shorter side is at least minRemnantSide.
     * Occupancy = piece footprints + already marked remnants (both inflated by
     * kerf to the right/bottom – mirrors the engine's placement convention).
     *
     * @return FreeRect[]
     */
    private function findAutoRemnants(Sheet $sheet, int &$workBudget): array
    {
        $remnants = [];

        while (count($remnants) < self::MAX_REMNANTS_PER_SHEET && $workBudget > 0) {
            $occupied = $this->collectOccupiedRects($sheet, $remnants);
            $rect     = $this->largestEmptyRect(
                $occupied,
                $sheet->format->width,
                $sheet->format->height,
                $workBudget
            );

            if ($rect === null || min($rect->width, $rect->height) < $this->minRemnantSide) {
                break;
            }
            $remnants[] = $rect;
        }

        return $remnants;
    }

    /**
     * DEFINED mode: greedily places registered remnant formats (largest area
     * first, each repeatedly while it fits). Candidate anchors are the
     * compressed footprint-edge coordinates; the lexicographically smallest
     * (y, x) position wins (top-left heuristic). Rotation is tried only when
     * grainDirection is 'none', unrotated orientation first.
     *
     * @return FreeRect[]
     */
    private function findDefinedRemnants(Sheet $sheet, int &$workBudget): array
    {
        if (empty($this->remnantFormats)) {
            return [];
        }

        $sw = $sheet->format->width;
        $sh = $sheet->format->height;

        $formats = $this->remnantFormats;
        usort($formats, fn($a, $b) => ($b[0] * $b[1]) <=> ($a[0] * $a[1]));

        $remnants = [];

        foreach ($formats as [$fw, $fh]) {
            $orientations = [[$fw, $fh]];
            if ($this->grainDirection === 'none' && $fw !== $fh) {
                $orientations[] = [$fh, $fw];
            }

            while (count($remnants) < self::MAX_REMNANTS_PER_SHEET && $workBudget > 0) {
                $occupied = $this->collectOccupiedRects($sheet, $remnants);

                $xs = [0.0];
                $ys = [0.0];
                foreach ($occupied as [$ox, $oy, $ow, $oh]) {
                    $xs[] = $ox;
                    $xs[] = $ox + $ow;
                    $ys[] = $oy;
                    $ys[] = $oy + $oh;
                }
                $xs = $this->uniqueSorted($xs);
                $ys = $this->uniqueSorted($ys);

                $workBudget -= count($xs) * count($ys) * max(1, count($occupied));

                $best = null;   // [x, y, w, h]
                foreach ($ys as $y) {
                    foreach ($xs as $x) {
                        foreach ($orientations as [$w, $h]) {
                            if ($this->rectIsFree($x, $y, $w, $h, $occupied, $sw, $sh)) {
                                $best = [$x, $y, $w, $h];
                                break 3;   // first fit in (y, x) order wins
                            }
                        }
                    }
                }

                if ($best === null) {
                    break;   // this format no longer fits anywhere
                }
                $remnants[] = new FreeRect($best[0], $best[1], $best[2], $best[3]);
            }
        }

        return $remnants;
    }

    /**
     * Footprints (dimension + kerf to the right/bottom, clipped to the sheet)
     * of placed pieces and already marked remnants.
     *
     * @param  FreeRect[] $remnants
     * @return array<int, array{float, float, float, float}>  Each [x, y, w, h].
     */
    private function collectOccupiedRects(Sheet $sheet, array $remnants): array
    {
        $sw = $sheet->format->width;
        $sh = $sheet->format->height;

        $rects = [];
        foreach ($sheet->pieces as $p) {
            $rects[] = [
                $p->x,
                $p->y,
                min($p->width  + $this->kerf, $sw - $p->x),
                min($p->height + $this->kerf, $sh - $p->y),
            ];
        }
        foreach ($remnants as $r) {
            $rects[] = [
                $r->x,
                $r->y,
                min($r->width  + $this->kerf, $sw - $r->x),
                min($r->height + $this->kerf, $sh - $r->y),
            ];
        }
        return $rects;
    }

    /**
     * Finds the largest axis-aligned empty rectangle on a sw × sh sheet given
     * occupied rectangles. Coordinate compression over occupied edges +
     * "largest rectangle in histogram" per grid row (widths/heights in mm).
     * Deterministic tie-break: larger area, then smaller y, then smaller x.
     *
     * Decrements $workBudget by the number of performed grid-cell tests so the
     * caller can enforce the deterministic REMNANT_WORK_BUDGET cap.
     *
     * @param array<int, array{float, float, float, float}> $occupied
     */
    private function largestEmptyRect(array $occupied, float $sw, float $sh, int &$workBudget): ?FreeRect
    {
        if ($sw <= 0 || $sh <= 0) {
            return null;
        }

        $xs = [0.0, $sw];
        $ys = [0.0, $sh];
        foreach ($occupied as [$ox, $oy, $ow, $oh]) {
            foreach ([$ox, $ox + $ow] as $x) {
                if ($x > 0 && $x < $sw) {
                    $xs[] = $x;
                }
            }
            foreach ([$oy, $oy + $oh] as $y) {
                if ($y > 0 && $y < $sh) {
                    $ys[] = $y;
                }
            }
        }
        $xs = $this->uniqueSorted($xs);
        $ys = $this->uniqueSorted($ys);

        $cols = count($xs) - 1;
        $rows = count($ys) - 1;
        if ($cols < 1 || $rows < 1) {
            return null;
        }

        $workBudget -= $rows * $cols * max(1, count($occupied));

        // Occupancy of compressed cells (cell-centre containment test).
        $grid = [];
        for ($r = 0; $r < $rows; $r++) {
            $cy = ($ys[$r] + $ys[$r + 1]) / 2;
            for ($c = 0; $c < $cols; $c++) {
                $cx   = ($xs[$c] + $xs[$c + 1]) / 2;
                $free = true;
                foreach ($occupied as [$ox, $oy, $ow, $oh]) {
                    if ($cx > $ox && $cx < $ox + $ow && $cy > $oy && $cy < $oy + $oh) {
                        $free = false;
                        break;
                    }
                }
                $grid[$r][$c] = $free;
            }
        }

        $best    = null;   // [area, y, x, width, height]
        $heights = array_fill(0, $cols, 0.0);   // free run (mm) ending at current row

        for ($r = 0; $r < $rows; $r++) {
            $rowH = $ys[$r + 1] - $ys[$r];
            for ($c = 0; $c < $cols; $c++) {
                $heights[$c] = $grid[$r][$c] ? $heights[$c] + $rowH : 0.0;
            }

            // Largest rectangle in histogram (stack of column indices with
            // increasing heights); widths are real mm distances between
            // compressed x coordinates. Rect bottom = $ys[$r + 1].
            $stack = [];
            for ($c = 0; $c <= $cols; $c++) {
                $h = $c < $cols ? $heights[$c] : 0.0;
                while (!empty($stack) && $heights[end($stack)] > $h + 1e-9) {
                    $topC   = array_pop($stack);
                    $height = $heights[$topC];
                    $left   = empty($stack) ? 0 : end($stack) + 1;
                    $width  = $xs[$c] - $xs[$left];
                    $area   = $width * $height;
                    if ($area > 1e-9) {
                        $y = $ys[$r + 1] - $height;
                        $x = $xs[$left];
                        if ($best === null
                            || $area > $best[0] + 1e-9
                            || (abs($area - $best[0]) <= 1e-9
                                && ($y < $best[1] - 1e-9
                                    || (abs($y - $best[1]) <= 1e-9 && $x < $best[2] - 1e-9)))
                        ) {
                            $best = [$area, $y, $x, $width, $height];
                        }
                    }
                }
                $stack[] = $c;
            }
        }

        return $best === null
            ? null
            : new FreeRect($best[2], $best[1], $best[3], $best[4]);
    }

    /**
     * Whether the axis-aligned rectangle lies fully on the sheet and does not
     * intersect any occupied rectangle.
     *
     * @param array<int, array{float, float, float, float}> $occupied
     */
    private function rectIsFree(
        float $x,
        float $y,
        float $w,
        float $h,
        array $occupied,
        float $sw,
        float $sh
    ): bool {
        if ($x < -1e-9 || $y < -1e-9 || $x + $w > $sw + 1e-9 || $y + $h > $sh + 1e-9) {
            return false;
        }
        foreach ($occupied as [$ox, $oy, $ow, $oh]) {
            if ($x < $ox + $ow - 1e-9 && $x + $w > $ox + 1e-9
                && $y < $oy + $oh - 1e-9 && $y + $h > $oy + 1e-9) {
                return false;
            }
        }
        return true;
    }

    /**
     * Sorts float coordinates ascending and drops near-duplicates (1e-6).
     *
     * @param  float[] $values
     * @return float[]
     */
    private function uniqueSorted(array $values): array
    {
        sort($values);
        $out = [];
        foreach ($values as $v) {
            if (empty($out) || $v - end($out) > 1e-6) {
                $out[] = $v;
            }
        }
        return $out;
    }
}
