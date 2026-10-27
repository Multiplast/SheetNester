<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Represents a single piece (order line) to be cut.
 *
 * @internal Used by the engine; not part of the public API.
 */
class Piece
{
    public function __construct(
        public float $width,
        public float $height,
        public int   $qty,
    ) {}
}
