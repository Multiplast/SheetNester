<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * One saw cut on a sheet – a merged run of collinear piece edges. A horizontal
 * cut lies at y = position and spans x ∈ [start, end]; a vertical cut lies at
 * x = position and spans y ∈ [start, end]. Sheet borders are never cuts.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Cut
{
    public const HORIZONTAL = 'horizontal';
    public const VERTICAL   = 'vertical';

    public function __construct(
        public string $axis,
        public float  $position,
        public float  $start,
        public float  $end,
        public float  $length,
    ) {
    }

    /**
     * @return array<string, float>  horizontal: y / x_start / x_end / length,
     *                               vertical:   x / y_start / y_end / length
     */
    public function toLegacyArray(): array
    {
        if ($this->axis === self::HORIZONTAL) {
            return [
                'y'       => $this->position,
                'x_start' => $this->start,
                'x_end'   => $this->end,
                'length'  => $this->length,
            ];
        }
        return [
            'x'       => $this->position,
            'y_start' => $this->start,
            'y_end'   => $this->end,
            'length'  => $this->length,
        ];
    }
}
