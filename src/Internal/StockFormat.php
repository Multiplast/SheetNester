<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Represents an available stock sheet format with limited (or unlimited) inventory.
 *
 * @internal Used by the engine; not part of the public API.
 */
class StockFormat
{
    /** Sheets still available in this run (PHP_INT_MAX for unlimited stock). */
    public int $remaining;

    /**
     * @param int|null $stock Number of sheets available; null = unlimited.
     */
    public function __construct(
        public float $width,
        public float $height,
        public ?int  $stock,
    ) {
        $this->remaining = $stock ?? PHP_INT_MAX;
    }

    public function isUnlimited(): bool
    {
        return $this->stock === null;
    }

    /** Returns the total area of this sheet format in mm². */
    public function area(): float
    {
        return $this->width * $this->height;
    }
}
