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
    /**
     * @param int|null $line Index of the job's piece line this piece came from
     *                       (null for lookahead simulations).
     */
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public bool  $rotated,
        public ?int  $line = null,
    ) {}
}
