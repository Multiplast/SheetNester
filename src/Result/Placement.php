<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * A piece placed on a sheet: top-left corner and the dimensions as cut
 * (already swapped when the piece was rotated).
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Placement
{
    public function __construct(
        public float   $x,
        public float   $y,
        public float   $width,
        public float   $height,
        public bool    $rotated,
        public ?string $label = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'x'       => $this->x,
            'y'       => $this->y,
            'width'   => $this->width,
            'height'  => $this->height,
            'rotated' => $this->rotated,
            'label'   => $this->label,
        ];
    }

    /** @return array{width: float, height: float, x: float, y: float, rotated: bool} */
    public function toLegacyArray(): array
    {
        return [
            'width'   => $this->width,
            'height'  => $this->height,
            'x'       => $this->x,
            'y'       => $this->y,
            'rotated' => $this->rotated,
        ];
    }
}
