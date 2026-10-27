<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Post-processing step that tries to dissolve whole sheets by repacking the
 * pieces of the worst-utilised sheet into the free space of the others.
 *
 * @internal
 */
final class SheetEliminator
{
    public function __construct(private GuillotinePacker $packer)
    {
    }

    /**
     * Takes the sheet with the lowest piece-area utilisation, and attempts to
     * repack all its pieces into the free rectangles of the other sheets
     * (largest piece first, Best Area Fit across sheets). On success the sheet
     * is dropped and the attempt repeats – each success saves one whole board.
     *
     * Placements are simulated on deep copies of the target sheets, so a
     * failed attempt leaves everything untouched.
     *
     * MUST run BEFORE FormatReducer: the repacking relies on live freeRects,
     * which reduction leaves stale (they refer to the original, larger format
     * geometry).
     *
     * Note: a piece re-placed here keeps its actual (possibly rotated)
     * dimensions; its `rotated` flag is relative to those, which only affects
     * the display hatch/legend, never the geometry.
     *
     * @param Sheet[] $sheets  Modified in place (re-indexed on elimination).
     */
    public function eliminate(array &$sheets): void
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
                    $targets[$i] = $this->packer->cloneSheet($sheet);
                }
            }

            $pieces = $sheets[$worstIdx]->pieces;
            usort($pieces, fn($a, $b) => ($b->width * $b->height) <=> ($a->width * $a->height));

            $allPlaced = true;
            foreach ($pieces as $p) {
                if (!$this->packer->placeOnExisting($targets, $p->width, $p->height, $p->line)) {
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
}
