<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Represents an available stock sheet format with limited inventory.
 *
 * @internal Used by the engine; not part of the public API.
 */
class StockFormat
{
    public int $remaining;

    public function __construct(
        public float $width,
        public float $height,
        public int   $stock,
    ) {
        $this->remaining = $stock;
    }

    /** Returns the total area of this sheet format in mm². */
    public function area(): float
    {
        return $this->width * $this->height;
    }
}
