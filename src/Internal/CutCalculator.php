<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\Result\Cut;
use Multiplast\SheetNester\Result\CutPlan;

/**
 * Derives saw cuts (merged collinear segments) and the seal length from the
 * placed pieces of a sheet.
 *
 * @internal
 */
final class CutCalculator
{
    /**
     * @param string $grain One of the Grain constants – decides the seal axis.
     */
    public function __construct(private string $grain)
    {
    }

    /**
     * Calculates internal cut lengths for a single sheet.
     *
     * Cut algorithm:
     *   1. Collect all piece edges as axis-aligned segments (horizontal / vertical).
     *   2. Discard segments on the sheet border (those are edges, not cuts).
     *   3. Merge collinear overlapping/touching segments to avoid double-counting
     *      shared edges between adjacent pieces.
     *   4. Return merged segment list + totals.
     */
    public function forSheet(Sheet $sheet): CutPlan
    {
        $sw = $sheet->format->width;
        $sh = $sheet->format->height;

        // Horizontal segments keyed by Y coordinate → list of [x1, x2]
        // Vertical segments   keyed by X coordinate → list of [y1, y2]
        $horizSegs = [];
        $vertSegs  = [];

        foreach ($sheet->pieces as $p) {
            $x1 = $p->x;
            $y1 = $p->y;
            $x2 = $p->x + $p->width;
            $y2 = $p->y + $p->height;

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

        [$horizMm, $horizList] = $this->mergeSegments($horizSegs, Cut::HORIZONTAL);
        [$vertMm,  $vertList]  = $this->mergeSegments($vertSegs,  Cut::VERTICAL);

        return new CutPlan(
            round($horizMm, 1),
            round($vertMm,  1),
            round($horizMm + $vertMm, 1),
            $horizList,
            $vertList
        );
    }

    /** Whether the material has channels to seal at all. */
    public function hasSeal(): bool
    {
        return $this->grain !== Grain::NONE;
    }

    /**
     * Length (mm) of open channel ends to seal on this sheet: for every piece
     * the two edges perpendicular to the channels – Grain::ALONG_WIDTH → left
     * and right edge (2 × height), Grain::ALONG_HEIGHT → top and bottom edge
     * (2 × width). Null for a material without channels.
     */
    public function sealMm(Sheet $sheet): ?float
    {
        if (!$this->hasSeal()) {
            return null;
        }
        $alongWidth = $this->grain === Grain::ALONG_WIDTH;
        $mm = 0.0;
        foreach ($sheet->pieces as $p) {
            $mm += ($alongWidth ? $p->height : $p->width) * 2;
        }
        return round($mm, 1);
    }

    /**
     * Merges overlapping/touching collinear segments, returns [totalLength, Cut[]].
     *
     * @param  array<string, array<int, array{float, float}>> $segsByCoord
     * @param  string                                          $axis  Cut::HORIZONTAL | Cut::VERTICAL
     * @return array{float, Cut[]}
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
                $list[] = new Cut($axis, $coord, $start, $end, $len);
            }
        }

        // Sort output: by position (y / x), then by start (x_start / y_start)
        usort($list, fn(Cut $a, Cut $b) => $a->position <=> $b->position ?: $a->start <=> $b->start);

        return [round($total, 1), $list];
    }
}
