<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * How many sheets of one stock format the plan uses and how many are left.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class StockUsage
{
    public function __construct(
        public float $width,
        public float $height,
        public int   $used,
        public int   $remaining,
    ) {
    }

    /** @return array{width: float, height: float, used: int, remaining: int} */
    public function toLegacyArray(): array
    {
        return [
            'width'     => $this->width,
            'height'    => $this->height,
            'used'      => $this->used,
            'remaining' => $this->remaining,
        ];
    }
}
