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

    /** The format the sheet had before FormatUpsizer enlarged it; null otherwise. */
    public ?StockFormat $upsizedFrom = null;

    /** Options\UpsizeMethod::REMNANT / MULTIPLE that enlarged the sheet; null when it was not enlarged. */
    public ?string $upsizeMethod = null;

    /**
     * UpsizeMethod::MULTIPLE: where the sheets of the plan nested into this
     * one lie, as [x, y, width, height] of the $upsizedFrom format, in order.
     *
     * @var array<int, array{float, float, float, float}>
     */
    public array $nestedSheets = [];

    /** UpsizeMethod::MULTIPLE: how many sheets of the $upsizedFrom format the format holds. */
    public int $nestedCapacity = 0;

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
