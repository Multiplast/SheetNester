<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * A usable leftover rectangle marked on a sheet (advisory – see
 * Internal\RemnantFinder for what "usable" guarantees and what it does not).
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Remnant
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
    }

    public function areaM2(): float
    {
        return $this->width * $this->height / 1_000_000;
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    public function toArray(): array
    {
        return [
            'x'      => $this->x,
            'y'      => $this->y,
            'width'  => $this->width,
            'height' => $this->height,
        ];
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    public function toLegacyArray(): array
    {
        return $this->toArray();
    }
}
