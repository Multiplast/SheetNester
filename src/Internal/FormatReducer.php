<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Post-processing step that reassigns sheets to smaller stock formats when
 * all their pieces still fit.
 *
 * @internal
 */
final class FormatReducer
{
    /**
     * @param StockFormat[] $stockFormats Registered formats; never mutated.
     */
    public function __construct(private array $stockFormats, private float $kerf)
    {
    }

    /**
     * For each sheet check whether a smaller available stock format (with
     * remaining stock > 0) could hold all pieces on that sheet. If yes,
     * reassign the sheet to the smaller format, freeing up the larger format's
     * stock slot and consuming one of the smaller format's.
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
    public function reduce(array &$sheets): void
    {
        // Build a live inventory map keyed by "width:height"
        // reflecting stock consumed by this run so far.
        // We start from the original stock and subtract sheets already assigned.
        $inventory = [];
        foreach ($this->stockFormats as $fmt) {
            $key = $fmt->width . ':' . $fmt->height;
            $inventory[$key] = $fmt->stock ?? PHP_INT_MAX;   // null = unlimited
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
}
