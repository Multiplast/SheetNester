<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

use Multiplast\SheetNester\Grain;

/**
 * Properties of the sheet material and the saw that apply to the whole job.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Material
{
    /**
     * @param float  $kerf  Blade kerf in mm – added to the right and bottom of
     *                      every piece footprint. 0 for laser/water-jet cuts.
     * @param string $grain One of the Grain constants.
     */
    public function __construct(
        public float  $kerf = 0.0,
        public string $grain = Grain::NONE,
    ) {
        if (!is_finite($kerf) || $kerf < 0) {
            throw new \InvalidArgumentException('kerf must be a finite number >= 0.');
        }
        Grain::assertValid($grain);
    }

    public function allowsRotation(): bool
    {
        return Grain::allowsRotation($this->grain);
    }
}
