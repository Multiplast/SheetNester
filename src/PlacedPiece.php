<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

/**
 * Represents a placed piece on a specific sheet.
 *
 * @internal Used by SheetNester; not part of the public API.
 */
class PlacedPiece
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public bool  $rotated,
    ) {}
}
