<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Options;

/**
 * Finishing step on the winning plan: a sheet may take a larger stock format.
 * Unlike Remnants (which only marks leftovers on the finished plan) this
 * option changes the material consumption. The nesting itself never changes:
 * pieces keep their positions on their sheet.
 *
 * Scope – which sheets the step considers:
 *  - none():      never (default).
 *  - lastSheet(): only the last sheet of the plan – the sheet opened last,
 *                 which holds the leftover pieces (with one sheet, that one).
 *  - allSheets(): every sheet.
 *
 * Methods – UpsizeMethod objects, each with its own settings, tried one after
 * another in the order given; a sheet that one method changed is left alone
 * by the later ones:
 *
 *     Upsize::lastSheet(methods: [
 *         UpsizeMethod::multiple(widthTolerance: 200),
 *         UpsizeMethod::remnant(minWidth: 2500, minHeight: 1000),
 *     ])
 *
 * Without methods the threshold arguments are a shorthand for the single
 * remnant method: lastSheet(2500, 1000) ≡ lastSheet(methods:
 * [UpsizeMethod::remnant(2500, 1000)]). See UpsizeMethod for what each
 * method does.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class Upsize
{
    public const NONE       = 'none';
    public const LAST_SHEET = 'last_sheet';
    public const ALL_SHEETS = 'all_sheets';

    /**
     * $minWidth, $minHeight and $formats are the threshold of the remnant
     * method (0.0 / [] without one) – the 2.2 properties, kept as they were.
     *
     * @param array<int, array{float, float}> $formats
     * @param UpsizeMethod[]                  $methods In the order they are tried.
     */
    private function __construct(
        public string $scope,
        public float  $minWidth,
        public float  $minHeight,
        public array  $formats,
        public array  $methods = [],
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE, 0.0, 0.0, []);
    }

    /**
     * Only the last sheet of the plan (the one opened last).
     *
     * @param array<int, array{float, float}>|null $formats [[width, height], ...] in mm –
     *        instead of minWidth / minHeight: the remnant must fit one of them.
     * @param UpsizeMethod[]|null $methods Instead of the threshold: the methods, in the
     *        order they are tried, each method at most once.
     */
    public static function lastSheet(
        ?float $minWidth = null,
        ?float $minHeight = null,
        ?array $formats = null,
        ?array $methods = null,
    ): self {
        return self::make(self::LAST_SHEET, $minWidth, $minHeight, $formats, $methods);
    }

    /**
     * Every sheet. With the remnant method and many stock formats this also
     * enlarges sheets for a few millimetres of waste – prefer lastSheet()
     * unless that is wanted.
     *
     * @param array<int, array{float, float}>|null $formats See lastSheet().
     * @param UpsizeMethod[]|null                  $methods See lastSheet().
     */
    public static function allSheets(
        ?float $minWidth = null,
        ?float $minHeight = null,
        ?array $formats = null,
        ?array $methods = null,
    ): self {
        return self::make(self::ALL_SHEETS, $minWidth, $minHeight, $formats, $methods);
    }

    public function enabled(): bool
    {
        return $this->scope !== self::NONE;
    }

    /** The method of the given UpsizeMethod type (REMNANT, MULTIPLE), if it is tried. */
    public function method(string $type): ?UpsizeMethod
    {
        foreach ($this->methods as $method) {
            if ($method->type === $type) {
                return $method;
            }
        }
        return null;
    }

    /** Whether the remnant method's threshold is a list of formats (else a minimum size). */
    public function usesFormats(): bool
    {
        return $this->formats !== [];
    }

    /**
     * @param array<int, array{float, float}>|null $formats
     * @param array<int|string, mixed>|null        $methods
     */
    private static function make(string $scope, ?float $minWidth, ?float $minHeight, ?array $formats, ?array $methods): self
    {
        if ($methods === null) {
            $methods = [UpsizeMethod::remnant($minWidth, $minHeight, $formats)];
        } elseif ($minWidth !== null || $minHeight !== null || $formats !== null) {
            throw new \InvalidArgumentException(
                'Give Upsize either a threshold (minWidth / minHeight or formats) or methods, not both – '
                . 'the threshold then goes to UpsizeMethod::remnant().'
            );
        }

        if ($methods === []) {
            throw new \InvalidArgumentException('Upsize needs at least one method (UpsizeMethod::remnant(), UpsizeMethod::multiple()).');
        }
        $list  = [];
        $types = [];
        foreach ($methods as $i => $method) {
            if (!$method instanceof UpsizeMethod) {
                throw new \InvalidArgumentException(
                    "Upsize method #{$i} must be an UpsizeMethod (UpsizeMethod::remnant(), UpsizeMethod::multiple()), "
                    . get_debug_type($method) . ' given.'
                );
            }
            if (isset($types[$method->type])) {
                throw new \InvalidArgumentException("Upsize method '{$method->type}' is listed twice.");
            }
            $types[$method->type] = true;
            $list[] = $method;
        }

        $remnant = null;
        foreach ($list as $method) {
            if ($method->type === UpsizeMethod::REMNANT) {
                $remnant = $method;
            }
        }
        return $remnant === null
            ? new self($scope, 0.0, 0.0, [], $list)
            : new self($scope, $remnant->minWidth, $remnant->minHeight, $remnant->formats, $list);
    }
}
