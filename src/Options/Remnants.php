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
 * usable remnant (see Internal\FormatUpsizer): UPSIZE_LAST considers only the
 * leftover sheet of the plan (lowest utilisation – in practice the last,
 * partially filled one), UPSIZE_ALL every sheet. The pieces keep their
 * positions, only the format of a sheet changes.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Remnants
{
    public const NONE    = 'none';
    public const AUTO    = 'auto';
    public const DEFINED = 'defined';

    /** $upsize: never enlarge a sheet – marking only. */
    public const UPSIZE_NONE = 'none';

    /** $upsize: enlarge at most the leftover sheet (lowest utilisation). */
    public const UPSIZE_LAST = 'last';

    /** $upsize: enlarge every sheet whose waste a larger format turns into a usable remnant. */
    public const UPSIZE_ALL = 'all';

    /**
     * Shorter of the two minimum sides of the auto mode.
     *
     * @deprecated since 2.1 – use $minWidth / $minHeight.
     */
    public float $minSide;

    /**
     * @param array<int, array{float, float}> $formats
     * @param string $upsize One of the UPSIZE_* constants.
     */
    private function __construct(
        public string $mode,
        public float  $minWidth,
        public float  $minHeight,
        public array  $formats,
        public string $upsize,
    ) {
        $this->minSide = min($minWidth, $minHeight);
    }

    public static function none(): self
    {
        return new self(self::NONE, 0.0, 0.0, [], self::UPSIZE_NONE);
    }

    /**
     * Mark the largest empty rectangles whose shorter side is at least $minSide
     * mm – the same as atLeast($minSide, $minSide).
     *
     * @param string $upsize UPSIZE_NONE (default), UPSIZE_LAST or UPSIZE_ALL –
     *                       which sheets may take a larger stock format when
     *                       that turns their waste into a usable remnant.
     */
    public static function auto(float $minSide = 200.0, string $upsize = self::UPSIZE_NONE): self
    {
        if (!is_finite($minSide) || $minSide <= 0) {
            throw new \InvalidArgumentException('minSide must be a positive number.');
        }
        self::assertUpsize($upsize);
        return new self(self::AUTO, $minSide, $minSide, [], $upsize);
    }

    /**
     * Mark the largest empty rectangles that are at least $width × $height mm,
     * width along X and height along Y like the rest of the job. With channels
     * a remnant cannot be turned, so the minimum is orientation-bound; with
     * Grain::NONE a rectangle qualifies in either orientation.
     *
     * @param string $upsize UPSIZE_NONE (default), UPSIZE_LAST or UPSIZE_ALL –
     *                       which sheets may take a larger stock format when
     *                       that turns their waste into a usable remnant.
     */
    public static function atLeast(float $width, float $height, string $upsize = self::UPSIZE_NONE): self
    {
        if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('The minimum remnant width and height must be positive numbers.');
        }
        self::assertUpsize($upsize);
        return new self(self::AUTO, $width, $height, [], $upsize);
    }

    /**
     * Fit the given remnant formats (largest area first, each repeatedly while
     * it fits) into the free area of every sheet.
     *
     * @param array<int, array{float, float}> $formats  [[width, height], ...] in mm
     * @param string $upsize UPSIZE_NONE (default), UPSIZE_LAST or UPSIZE_ALL –
     *                       which sheets may take a larger stock format when
     *                       that turns their waste into a usable remnant.
     */
    public static function defined(array $formats, string $upsize = self::UPSIZE_NONE): self
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
        self::assertUpsize($upsize);
        return new self(self::DEFINED, 0.0, 0.0, $clean, $upsize);
    }

    public function enabled(): bool
    {
        return $this->mode !== self::NONE;
    }

    /** Whether any sheet may take a larger format (UPSIZE_LAST or UPSIZE_ALL). */
    public function upsizes(): bool
    {
        return $this->upsize !== self::UPSIZE_NONE;
    }

    private static function assertUpsize(string $upsize): void
    {
        if (!in_array($upsize, [self::UPSIZE_NONE, self::UPSIZE_LAST, self::UPSIZE_ALL], true)) {
            throw new \InvalidArgumentException(
                "Unknown upsize mode '{$upsize}'; use Remnants::UPSIZE_NONE, UPSIZE_LAST or UPSIZE_ALL."
            );
        }
    }
}
