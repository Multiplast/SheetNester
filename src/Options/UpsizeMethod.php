<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * One method of the Upsize finishing step, with its own settings. Methods are
 * listed in Upsize::lastSheet() / allSheets() (methods: […]) and tried in
 * that order; a sheet that one method changed is left alone by the later ones.
 *
 *  - remnant(): a larger format when that turns the sheet's waste into a
 *    usable remnant. Every available larger format that still holds the
 *    pieces at their positions is valued by its net material – format area
 *    minus the remnants that meet the threshold – and the sheet moves only on
 *    a strict improvement; ties go to the smaller format, so a fully used
 *    sheet never grows. The threshold is a minimum size (minWidth × minHeight
 *    in mm, width along X like the rest of the job; orientation-bound with
 *    channels, either way round with Grain::NONE) or a list of remnant formats
 *    one of which must fit. It is independent of the Remnants option.
 *
 *  - multiple(): the sheet is cut out of an available stock format that holds
 *    its format several times – columns side by side along the width, rows
 *    along the height, the sheet never turned (so the channels keep their
 *    direction). A stock format qualifies when, per direction, the sheet fits
 *    at most maxWidthMultiple / maxHeightMultiple times, at least two times
 *    in all, and what is left over beyond those sheets is at most
 *    widthTolerance / heightTolerance mm. With the default tolerance of 0
 *    only exact multiples qualify (3500 × 2100 → 7000 × 2100, 2000 × 1050 →
 *    4000 × 2100); with a tolerance 1900 × 2100 → 4000 × 2100 (200 mm left
 *    along the width) or 2000 × 1200 → 4000 × 2100 (900 mm left along the
 *    height) qualify as well. Further parts of the stock format take other
 *    sheets of the same format; an empty part and the leftover strip are
 *    reported as remnants (Options\Remnants). The parts are separated by the
 *    saw kerf where the leftover leaves room for it; on an exact multiple the
 *    kerf between them is ignored – a 7000 holds exactly two 3500 layouts.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class UpsizeMethod
{
    /** A larger format when that turns the waste into a usable remnant. */
    public const REMNANT = 'remnant';

    /** Cut the sheet out of a stock format that holds its format several times. */
    public const MULTIPLE = 'multiple';

    /** Default of maxWidthMultiple and maxHeightMultiple. */
    public const DEFAULT_MAX_MULTIPLE = 3;

    /**
     * @param string                          $type     REMNANT or MULTIPLE.
     * @param array<int, array{float, float}> $formats  remnant(): threshold as remnant formats.
     */
    private function __construct(
        public string $type,
        public float  $minWidth = 0.0,
        public float  $minHeight = 0.0,
        public array  $formats = [],
        public int    $maxWidthMultiple = 0,
        public int    $maxHeightMultiple = 0,
        public float  $widthTolerance = 0.0,
        public float  $heightTolerance = 0.0,
    ) {
    }

    /**
     * A larger format when that turns the waste into a usable remnant – the
     * threshold either as minWidth × minHeight or as formats, not both.
     *
     * @param array<int, array{float, float}>|null $formats [[width, height], ...] in mm –
     *        instead of minWidth / minHeight: the remnant must fit one of them.
     */
    public static function remnant(?float $minWidth = null, ?float $minHeight = null, ?array $formats = null): self
    {
        if ($formats !== null) {
            if ($minWidth !== null || $minHeight !== null) {
                throw new \InvalidArgumentException('Give the upsize threshold either as minWidth / minHeight or as formats, not both.');
            }
            if ($formats === []) {
                throw new \InvalidArgumentException('The upsize formats need at least one [width, height] format.');
            }
            $clean = [];
            foreach ($formats as $i => $f) {
                if (!is_array($f) || count($f) !== 2 || !is_numeric($f[0] ?? null) || !is_numeric($f[1] ?? null)
                    || $f[0] <= 0 || $f[1] <= 0) {
                    throw new \InvalidArgumentException("Upsize format #{$i} must be [width, height] with positive numbers.");
                }
                $clean[] = [(float) $f[0], (float) $f[1]];
            }
            return new self(self::REMNANT, formats: $clean);
        }

        if ($minWidth === null || $minHeight === null
            || !is_finite($minWidth) || !is_finite($minHeight) || $minWidth <= 0 || $minHeight <= 0) {
            throw new \InvalidArgumentException('The upsize threshold needs a positive minWidth and minHeight (or formats).');
        }
        return new self(self::REMNANT, $minWidth, $minHeight);
    }

    /**
     * Cut the sheet out of a stock format that holds its format several times.
     *
     * @param int   $maxWidthMultiple  At most this many sheets side by side along the
     *                                 width (1 = never along the width).
     * @param int   $maxHeightMultiple At most this many sheets along the height.
     * @param float $widthTolerance    Most mm the stock format may be wider than the
     *                                 sheets it holds side by side.
     * @param float $heightTolerance   Most mm the stock format may be higher than the
     *                                 sheets it holds along the height.
     */
    public static function multiple(
        int   $maxWidthMultiple = self::DEFAULT_MAX_MULTIPLE,
        int   $maxHeightMultiple = self::DEFAULT_MAX_MULTIPLE,
        float $widthTolerance = 0.0,
        float $heightTolerance = 0.0,
    ): self {
        if ($maxWidthMultiple < 1 || $maxHeightMultiple < 1) {
            throw new \InvalidArgumentException('maxWidthMultiple and maxHeightMultiple must be at least 1.');
        }
        if ($maxWidthMultiple < 2 && $maxHeightMultiple < 2) {
            throw new \InvalidArgumentException('maxWidthMultiple or maxHeightMultiple must be at least 2.');
        }
        if (!is_finite($widthTolerance) || !is_finite($heightTolerance) || $widthTolerance < 0 || $heightTolerance < 0) {
            throw new \InvalidArgumentException('widthTolerance and heightTolerance must be zero or positive.');
        }
        return new self(
            self::MULTIPLE,
            maxWidthMultiple: $maxWidthMultiple,
            maxHeightMultiple: $maxHeightMultiple,
            widthTolerance: $widthTolerance,
            heightTolerance: $heightTolerance,
        );
    }

    /** remnant(): whether the threshold is a list of formats (else a minimum size). */
    public function usesFormats(): bool
    {
        return $this->formats !== [];
    }
}
