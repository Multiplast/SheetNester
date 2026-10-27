<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Represents a single sheet (of a specific stock format) with placed pieces.
 *
 * @internal Used by the engine; not part of the public API.
 */
class Sheet
{
    /** @var PlacedPiece[] */
    public array $pieces = [];

    /** @var FreeRect[] */
    public array $freeRects;

    public function __construct(
        public int         $index,
        public StockFormat $format,
    ) {
        $this->freeRects = [new FreeRect(0, 0, $format->width, $format->height)];
    }

    /**
     * Returns what percentage of the sheet area is occupied by placed pieces (incl. kerf).
     */
    public function usedAreaPct(float $kerf): float
    {
        $usedArea = 0.0;
        foreach ($this->pieces as $p) {
            $usedArea += ($p->width + $kerf) * ($p->height + $kerf);
        }
        $totalArea = $this->format->area();
        if ($totalArea <= 0) {
            return 0.0;
        }
        return round(min(100.0, ($usedArea / $totalArea) * 100), 2);
    }
}
