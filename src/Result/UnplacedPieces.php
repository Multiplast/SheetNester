<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * Pieces of one size that could not be placed, with the reason.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class UnplacedPieces
{
    public function __construct(
        public float  $width,
        public float  $height,
        public int    $qty,
        public string $reason,
    ) {
    }

    /** @return array{width: float, height: float, qty: int, reason: string} */
    public function toLegacyArray(): array
    {
        return [
            'width'  => $this->width,
            'height' => $this->height,
            'qty'    => $this->qty,
            'reason' => $this->reason,
        ];
    }
}
