<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Represents a free (unoccupied) rectangular area on a sheet.
 *
 * @internal Used by the engine; not part of the public API.
 */
class FreeRect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    /** Returns the area of this free rectangle. */
    public function area(): float
    {
        return $this->width * $this->height;
    }
}
