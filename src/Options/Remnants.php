<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * Whether and how usable remnants (leftover rectangles worth keeping) are
 * marked on the finished plan. Marking never influences the placement.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Remnants
{
    public const NONE    = 'none';
    public const AUTO    = 'auto';
    public const DEFINED = 'defined';

    /**
     * @param array<int, array{float, float}> $formats
     */
    private function __construct(
        public string $mode,
        public float  $minSide,
        public array  $formats,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE, 0.0, []);
    }

    /**
     * Mark the largest empty rectangles whose shorter side is at least $minSide mm.
     */
    public static function auto(float $minSide = 200.0): self
    {
        if (!is_finite($minSide) || $minSide <= 0) {
            throw new \InvalidArgumentException('minSide must be a positive number.');
        }
        return new self(self::AUTO, $minSide, []);
    }

    /**
     * Fit the given remnant formats (largest area first, each repeatedly while
     * it fits) into the free area of every sheet.
     *
     * @param array<int, array{float, float}> $formats  [[width, height], ...] in mm
     */
    public static function defined(array $formats): self
    {
        if ($formats === []) {
            throw new \InvalidArgumentException('Remnants::defined() needs at least one [width, height] format.');
        }
        $clean = [];
        foreach ($formats as $i => $f) {
            if (!is_array($f) || count($f) !== 2 || !is_numeric($f[0] ?? null) || !is_numeric($f[1] ?? null)
                || $f[0] <= 0 || $f[1] <= 0) {
                throw new \InvalidArgumentException("Remnant format #{$i} must be [width, height] with positive numbers.");
            }
            $clean[] = [(float) $f[0], (float) $f[1]];
        }
        return new self(self::DEFINED, 0.0, $clean);
    }

    public function enabled(): bool
    {
        return $this->mode !== self::NONE;
    }
}
