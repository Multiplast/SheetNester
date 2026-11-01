<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * Whether a sheet may take a larger stock format when that turns its waste
 * into a usable remnant. Unlike Remnants (which only marks leftovers on the
 * finished plan) this option changes the material consumption.
 *
 * After the solver has found the plan with the least material, the sheets in
 * scope are offered every available larger format that still holds their
 * pieces at the same positions. A candidate is valued by its net material –
 * format area minus the remnants that meet this option's threshold – and the
 * sheet moves only on a strict improvement; ties go to the smaller format, so
 * a fully used sheet never grows. Pieces never move.
 *
 *  - none():      never (default).
 *  - lastSheet(): only the last sheet of the plan – the sheet opened last,
 *                 which holds the leftover pieces (with one sheet, that one).
 *  - allSheets(): every sheet.
 *
 * The threshold – what counts as a usable remnant – is either a minimum size
 * (minWidth × minHeight in mm, width along X like the rest of the job;
 * orientation-bound with channels, either way round with Grain::NONE) or a
 * list of remnant formats one of which must fit. It is independent of the
 * Remnants option: a job may mark every leftover from 200 mm and still
 * enlarge a sheet only for a 2500 × 1000 remnant.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Upsize
{
    public const NONE       = 'none';
    public const LAST_SHEET = 'last_sheet';
    public const ALL_SHEETS = 'all_sheets';

    /**
     * @param array<int, array{float, float}> $formats
     */
    private function __construct(
        public string $scope,
        public float  $minWidth,
        public float  $minHeight,
        public array  $formats,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE, 0.0, 0.0, []);
    }

    /**
     * Enlarge only the last sheet of the plan (the one opened last).
     *
     * @param array<int, array{float, float}>|null $formats  [[width, height], ...] in mm –
     *        instead of minWidth / minHeight: the remnant must fit one of them.
     */
    public static function lastSheet(?float $minWidth = null, ?float $minHeight = null, ?array $formats = null): self
    {
        return self::make(self::LAST_SHEET, $minWidth, $minHeight, $formats);
    }

    /**
     * Enlarge every sheet whose waste a larger format turns into a usable
     * remnant. With many stock formats this also enlarges sheets for a few
     * millimetres of waste – prefer lastSheet() unless that is wanted.
     *
     * @param array<int, array{float, float}>|null $formats  See lastSheet().
     */
    public static function allSheets(?float $minWidth = null, ?float $minHeight = null, ?array $formats = null): self
    {
        return self::make(self::ALL_SHEETS, $minWidth, $minHeight, $formats);
    }

    public function enabled(): bool
    {
        return $this->scope !== self::NONE;
    }

    /** Whether the threshold is a list of formats (else a minimum size). */
    public function usesFormats(): bool
    {
        return $this->formats !== [];
    }

    /**
     * @param array<int, array{float, float}>|null $formats
     */
    private static function make(string $scope, ?float $minWidth, ?float $minHeight, ?array $formats): self
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
            return new self($scope, 0.0, 0.0, $clean);
        }

        if ($minWidth === null || $minHeight === null
            || !is_finite($minWidth) || !is_finite($minHeight) || $minWidth <= 0 || $minHeight <= 0) {
            throw new \InvalidArgumentException('The upsize threshold needs a positive minWidth and minHeight (or formats).');
        }
        return new self($scope, $minWidth, $minHeight, []);
    }
}
