<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Derives saw cuts (merged collinear segments) and the seal length from the
 * placed pieces of a sheet.
 *
 * @internal
 */
final class CutCalculator
{
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
    public function forSheet(Sheet $sheet): array
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
}
