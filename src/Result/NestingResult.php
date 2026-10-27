<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

use Multiplast\SheetNester\Options\Objective;

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
     * @param float|null       $sealMm            Channel ends to seal over all sheets (mm):
     *                                            per piece the two edges perpendicular to
     *                                            the channels (see Grain). Null for a
     *                                            material without channels.
     */
    public function __construct(
        public array  $sheets,
        public array  $stockUsage,
        public array  $unplaced,
        public float  $wastePctAvg,
        public float  $utilizationPct,
        public float  $materialM2,
        public int    $cutCount,
        public float  $cutsMm,
        public float  $cutsHorizontalMm,
        public float  $cutsVerticalMm,
        public ?float $sealMm,
    ) {
    }

    /**
     * Rebuilds a result from toArray() output (e.g. JSON stored in a database).
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $cut = static fn(array $c): Cut => new Cut(
            (string) $c['axis'], (float) $c['position'], (float) $c['start'], (float) $c['end'], (float) $c['length']
        );

        $sheets = [];
        foreach ($a['sheets'] as $s) {
            $plan = new SheetPlan(
                (int) $s['index'],
                (float) $s['width'],
                (float) $s['height'],
                array_map(static fn(array $p): Placement => new Placement(
                    (float) $p['x'], (float) $p['y'], (float) $p['width'], (float) $p['height'],
                    (bool) $p['rotated'], $p['label'] ?? null
                ), $s['pieces']),
                (float) $s['used_pct'],
                new CutPlan(
                    (float) $s['cuts']['horizontal_mm'],
                    (float) $s['cuts']['vertical_mm'],
                    (float) $s['cuts']['total_mm'],
                    array_map($cut, $s['cuts']['horizontal']),
                    array_map($cut, $s['cuts']['vertical'])
                ),
                isset($s['seal_mm']) ? (float) $s['seal_mm'] : null
            );
            if (isset($s['remnants'])) {
                $plan->remnants  = array_map(static fn(array $r): Remnant => new Remnant(
                    (float) $r['x'], (float) $r['y'], (float) $r['width'], (float) $r['height']
                ), $s['remnants']);
                $plan->remnantM2 = isset($s['remnant_m2']) ? (float) $s['remnant_m2'] : null;
            }
            $sheets[] = $plan;
        }

        $t      = $a['totals'];
        $result = new self(
            $sheets,
            array_map(static fn(array $u): StockUsage => new StockUsage(
                (float) $u['width'], (float) $u['height'], (int) $u['used'],
                isset($u['remaining']) ? (int) $u['remaining'] : null
            ), $a['stock_usage']),
            array_map(static fn(array $u): UnplacedPieces => new UnplacedPieces(
                (float) $u['width'], (float) $u['height'], (int) $u['qty'], (string) $u['reason_code'], $u['label'] ?? null
            ), $a['unplaced']),
            (float) $t['waste_pct_avg'],
            (float) $t['utilization_pct'],
            (float) $t['material_m2'],
            (int) $t['cut_count'],
            (float) $t['cuts_mm'],
            (float) $t['cuts_horizontal_mm'],
            (float) $t['cuts_vertical_mm'],
            isset($t['seal_mm']) ? (float) $t['seal_mm'] : null
        );
        $result->objective     = (string) $a['objective'];
        $result->passes        = (int) $a['passes'];
        $result->timeBudgetHit = (bool) $a['time_budget_hit'];
        $result->calculationMs = (float) $a['calculation_ms'];
        $result->remnantM2     = isset($t['remnant_m2']) ? (float) $t['remnant_m2'] : null;
        $result->remnantPct    = isset($t['remnant_pct']) ? (float) $t['remnant_pct'] : null;

        return $result;
    }

    /**
     * Rebuilds a result from the 1.x array of SheetNester::calculate() /
     * toLegacyArray(). The 1.x array cannot tell "no channels" from a seal
     * length of 0, so sealMm comes back as 0.0 rather than null in that case.
     *
     * @param array<string, mixed> $a
     */
    public static function fromLegacyArray(array $a): self
    {
        $reasonCodes = [
            'Nedostatek skladových zásob.'                       => UnplacedPieces::REASON_STOCK,
            'Kus je větší než všechny dostupné formáty desek.'   => UnplacedPieces::REASON_TOO_LARGE,
        ];

        $sheets = [];
        foreach ($a['sheets'] as $s) {
            $c    = $s['cuts'];
            $plan = new SheetPlan(
                (int) $s['sheet_index'],
                (float) $s['stock_width'],
                (float) $s['stock_height'],
                array_map(static fn(array $p): Placement => new Placement(
                    (float) $p['x'], (float) $p['y'], (float) $p['width'], (float) $p['height'], (bool) $p['rotated']
                ), $s['pieces']),
                (float) $s['used_area_pct'],
                new CutPlan(
                    (float) $c['horizontal_mm'],
                    (float) $c['vertical_mm'],
                    (float) $c['total_mm'],
                    array_map(static fn(array $x): Cut => new Cut(
                        Cut::HORIZONTAL, (float) $x['y'], (float) $x['x_start'], (float) $x['x_end'], (float) $x['length']
                    ), $c['cuts_horizontal']),
                    array_map(static fn(array $x): Cut => new Cut(
                        Cut::VERTICAL, (float) $x['x'], (float) $x['y_start'], (float) $x['y_end'], (float) $x['length']
                    ), $c['cuts_vertical'])
                ),
                (float) ($c['seal_mm'] ?? 0.0)
            );
            if (array_key_exists('remnants', $s)) {
                $plan->remnants  = array_map(static fn(array $r): Remnant => new Remnant(
                    (float) $r['x'], (float) $r['y'], (float) $r['width'], (float) $r['height']
                ), $s['remnants']);
                $plan->remnantM2 = (float) ($s['remnant_area_m2'] ?? 0.0);
            }
            $sheets[] = $plan;
        }

        $result = new self(
            $sheets,
            array_map(static fn(array $u): StockUsage => new StockUsage(
                (float) $u['width'], (float) $u['height'], (int) $u['used'],
                isset($u['remaining']) ? (int) $u['remaining'] : null
            ), $a['stock_usage']),
            array_map(static fn(array $u): UnplacedPieces => new UnplacedPieces(
                (float) $u['width'], (float) $u['height'], (int) $u['qty'],
                $reasonCodes[$u['reason']] ?? (string) $u['reason']
            ), $a['unplaced_pieces']),
            (float) $a['total_waste_pct'],
            (float) $a['utilization_pct'],
            (float) $a['total_material_m2'],
            (int) $a['total_cuts_count'],
            (float) $a['total_cuts_mm'],
            (float) $a['total_cuts_horiz_mm'],
            (float) $a['total_cuts_vert_mm'],
            (float) ($a['total_seal_mm'] ?? 0.0)
        );
        // The 1.x 'smallest_fit' strategy was min_waste with a single pass.
        $strategy              = (string) ($a['strategy'] ?? '');
        $result->objective     = $strategy === 'smallest_fit' ? Objective::MIN_WASTE : $strategy;
        $result->passes        = (int) ($a['permutations_tested'] ?? 1);
        $result->timeBudgetHit = (bool) ($a['time_budget_hit'] ?? false);
        $result->calculationMs = (float) ($a['calculation_time_ms'] ?? 0.0);
        if (array_key_exists('total_remnant_m2', $a)) {
            $result->remnantM2  = (float) $a['total_remnant_m2'];
            $result->remnantPct = (float) ($a['total_remnant_pct'] ?? 0.0);
        }

        return $result;
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
     * The result as a plain array (JSON-friendly): run info, totals, sheet
     * plans, stock usage and unplaced pieces. Null stands for "not applicable"
     * (seal without channels, remnants when marking is off, unlimited stock).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'objective'       => $this->objective,
            'passes'          => $this->passes,
            'time_budget_hit' => $this->timeBudgetHit,
            'calculation_ms'  => $this->calculationMs,
            'totals'          => [
                'sheets'             => count($this->sheets),
                'material_m2'        => $this->materialM2,
                'utilization_pct'    => $this->utilizationPct,
                'waste_pct_avg'      => $this->wastePctAvg,
                'cut_count'          => $this->cutCount,
                'cuts_mm'            => $this->cutsMm,
                'cuts_horizontal_mm' => $this->cutsHorizontalMm,
                'cuts_vertical_mm'   => $this->cutsVerticalMm,
                'seal_mm'            => $this->sealMm,
                'remnant_m2'         => $this->remnantM2,
                'remnant_pct'        => $this->remnantPct,
                'unplaced'           => $this->unplacedCount(),
            ],
            'sheets'          => array_map(fn(SheetPlan $s) => $s->toArray(), $this->sheets),
            'stock_usage'     => array_map(fn(StockUsage $u) => $u->toArray(), $this->stockUsage),
            'unplaced'        => array_map(fn(UnplacedPieces $u) => $u->toArray(), $this->unplaced),
        ];
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
            'total_seal_mm'       => $this->sealMm ?? 0.0,
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
