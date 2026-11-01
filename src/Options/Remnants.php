<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * Whether and how usable remnants (leftover rectangles worth keeping) are
 * marked on the finished plan. Marking is informative only – it never
 * influences the placement, the formats or the consumption; it decides what
 * the result reports (remnants, remnant_m2, net_material_m2) and what the
 * renderers draw.
 *
 * Trading a larger stock sheet for a usable remnant is a separate option
 * with its own threshold: Options\Upsize (NestingJob::create(upsize: …)).
 * The $upsize argument of the factories below is its deprecated 2.1 form.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Remnants
{
    public const NONE    = 'none';
    public const AUTO    = 'auto';
    public const DEFINED = 'defined';

    /** @deprecated since 2.2 – use Upsize::none(). */
    public const UPSIZE_NONE = 'none';

    /** @deprecated since 2.2 – use Upsize::lastSheet() (now the last sheet of the plan). */
    public const UPSIZE_LAST = 'last';

    /** @deprecated since 2.2 – use Upsize::allSheets(). */
    public const UPSIZE_ALL = 'all';

    /**
     * Shorter of the two minimum sides of the auto mode.
     *
     * @deprecated since 2.1 – use $minWidth / $minHeight.
     */
    public float $minSide;

    /**
     * @param array<int, array{float, float}> $formats
     * @param string $upsize One of the deprecated UPSIZE_* constants.
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
     * @param string $upsize Deprecated since 2.2 – use NestingJob::create(upsize: Upsize::…).
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
     * @param string $upsize Deprecated since 2.2 – use NestingJob::create(upsize: Upsize::…).
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
     * @param string $upsize Deprecated since 2.2 – use NestingJob::create(upsize: Upsize::…).
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

    /**
     * Whether the deprecated $upsize argument asks for larger sheets.
     *
     * @deprecated since 2.2 – use NestingJob::upsize()->enabled().
     */
    public function upsizes(): bool
    {
        return $this->upsize !== self::UPSIZE_NONE;
    }

    /**
     * The deprecated $upsize argument as an Upsize option with this marking's
     * threshold (minimum size, or the formats of defined()).
     *
     * @internal Used by NestingJob::upsize().
     */
    public function legacyUpsize(): Upsize
    {
        if (!$this->upsizes() || !$this->enabled()) {
            return Upsize::none();
        }
        $formats = $this->mode === self::DEFINED ? $this->formats : null;
        $width   = $formats === null ? $this->minWidth : null;
        $height  = $formats === null ? $this->minHeight : null;

        return $this->upsize === self::UPSIZE_ALL
            ? Upsize::allSheets($width, $height, $formats)
            : Upsize::lastSheet($width, $height, $formats);
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
