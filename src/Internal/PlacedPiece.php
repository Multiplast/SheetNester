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
     * @param int|null $line    Index of the job's piece line this piece came from
     *                          (null for lookahead simulations).
     * @param float    $originX Top-left corner of the layout the piece was nested in:
     * @param float    $originY 0 / 0, except on a sheet that FormatUpsizer assembled
     *                          from several layouts with no kerf between them
     *                          (UpsizeMethod::MULTIPLE on an exact multiple) – there
     *                          an edge on the layout's origin is the dividing cut,
     *                          not a piece edge after a kerf strip.
     */
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public bool  $rotated,
        public ?int  $line = null,
        public float $originX = 0.0,
        public float $originY = 0.0,
    ) {}
}
