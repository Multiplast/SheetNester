<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

/**
 * Represents a single piece to be cut.
 *
 * @internal Used by SheetNester; not part of the public API.
 */
class Piece
{
    public function __construct(
        public float $width,
        public float $height,
        public int   $qty,
    ) {}
}
