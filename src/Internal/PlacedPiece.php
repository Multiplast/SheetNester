<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Represents a placed piece on a specific sheet.
 *
 * @internal Used by the engine; not part of the public API.
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
