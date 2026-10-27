<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

/**
 * Direction of the material's channels / grain, in the coordinate system of
 * the input (X = width, Y = height of both stock sheets and pieces).
 *
 * It decides two things:
 *  - rotation: pieces of a material with channels are never rotated,
 *  - sealing:  the open channel ends of a piece are the two edges
 *              perpendicular to the channels – ALONG_WIDTH → left + right
 *              edge (2 × height), ALONG_HEIGHT → top + bottom edge (2 × width).
 *
 * Placement itself does not depend on the axis – ALONG_WIDTH and ALONG_HEIGHT
 * give the same cutting plan; they differ only in the seal length.
 */
final class Grain
{
    /** Solid material: pieces may be rotated by 90°, nothing to seal. */
    public const NONE = 'none';

    /** Channels run along the width (X axis). */
    public const ALONG_WIDTH = 'along_width';

    /** Channels run along the height (Y axis). */
    public const ALONG_HEIGHT = 'along_height';

    public const ALL = [self::NONE, self::ALONG_WIDTH, self::ALONG_HEIGHT];

    private function __construct()
    {
    }

    public static function allowsRotation(string $grain): bool
    {
        return $grain === self::NONE;
    }

    /** @throws \InvalidArgumentException */
    public static function assertValid(string $grain): void
    {
        if (!in_array($grain, self::ALL, true)) {
            throw new \InvalidArgumentException(
                "Unknown grain '{$grain}'; use Grain::NONE, Grain::ALONG_WIDTH or Grain::ALONG_HEIGHT."
            );
        }
    }
}
