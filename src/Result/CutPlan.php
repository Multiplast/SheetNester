<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * Saw cuts of one sheet plus the derived lengths (mm).
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class CutPlan
{
    /**
     * @param Cut[] $horizontal  Sorted by y, then x_start.
     * @param Cut[] $vertical    Sorted by x, then y_start.
     */
    public function __construct(
        public float $horizontalMm,
        public float $verticalMm,
        public float $totalMm,
        public array $horizontal,
        public array $vertical,
    ) {
    }

    /** Number of saw passes (merged collinear segments). */
    public function count(): int
    {
        return count($this->horizontal) + count($this->vertical);
    }

    /**
     * @param float $sealMm  The sheet's seal length – 1.x reported it inside 'cuts'.
     * @return array<string, mixed>
     */
    public function toLegacyArray(float $sealMm): array
    {
        return [
            'horizontal_mm'   => $this->horizontalMm,
            'vertical_mm'     => $this->verticalMm,
            'total_mm'        => $this->totalMm,
            'seal_mm'         => $sealMm,
            'cuts_horizontal' => array_map(fn(Cut $c) => $c->toLegacyArray(), $this->horizontal),
            'cuts_vertical'   => array_map(fn(Cut $c) => $c->toLegacyArray(), $this->vertical),
        ];
    }
}
