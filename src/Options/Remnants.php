<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * Whether and how usable remnants (leftover rectangles worth keeping) are
 * marked on the finished plan, and whether the plan may trade a bigger stock
 * sheet for one.
 *
 * Marking alone never influences the placement. With $upsize a sheet may be
 * reassigned to a larger stock format when that turns its waste into a
 * usable remnant (see Internal\FormatUpsizer); the pieces keep their
 * positions, only the format of the sheet changes.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Remnants
{
    public const NONE    = 'none';
    public const AUTO    = 'auto';
    public const DEFINED = 'defined';

    /**
     * Shorter of the two minimum sides of the auto mode.
     *
     * @deprecated since 2.1 – use $minWidth / $minHeight.
     */
    public float $minSide;

    /**
     * @param array<int, array{float, float}> $formats
     */
    private function __construct(
        public string $mode,
        public float  $minWidth,
        public float  $minHeight,
        public array  $formats,
        public bool   $upsize,
    ) {
        $this->minSide = min($minWidth, $minHeight);
    }

    public static function none(): self
    {
        return new self(self::NONE, 0.0, 0.0, [], false);
    }

    /**
     * Mark the largest empty rectangles whose shorter side is at least $minSide
     * mm – the same as atLeast($minSide, $minSide).
     *
     * @param bool $upsize Allow a larger stock format for a sheet when that
     *                     turns its waste into a usable remnant.
     */
    public static function auto(float $minSide = 200.0, bool $upsize = false): self
    {
        if (!is_finite($minSide) || $minSide <= 0) {
            throw new \InvalidArgumentException('minSide must be a positive number.');
        }
        return new self(self::AUTO, $minSide, $minSide, [], $upsize);
    }

    /**
     * Mark the largest empty rectangles that are at least $width × $height mm,
     * width along X and height along Y like the rest of the job. With channels
     * a remnant cannot be turned, so the minimum is orientation-bound; with
     * Grain::NONE a rectangle qualifies in either orientation.
     *
     * @param bool $upsize Allow a larger stock format for a sheet when that
     *                     turns its waste into a usable remnant.
     */
    public static function atLeast(float $width, float $height, bool $upsize = false): self
    {
        if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('The minimum remnant width and height must be positive numbers.');
        }
        return new self(self::AUTO, $width, $height, [], $upsize);
    }

    /**
     * Fit the given remnant formats (largest area first, each repeatedly while
     * it fits) into the free area of every sheet.
     *
     * @param array<int, array{float, float}> $formats  [[width, height], ...] in mm
     * @param bool $upsize Allow a larger stock format for a sheet when that
     *                     turns its waste into a usable remnant.
     */
    public static function defined(array $formats, bool $upsize = false): self
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
        return new self(self::DEFINED, 0.0, 0.0, $clean, $upsize);
    }

    public function enabled(): bool
    {
        return $this->mode !== self::NONE;
    }
}
