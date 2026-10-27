<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Result;

/**
 * The cutting plan of one stock sheet: its format, the placed pieces, the
 * saw cuts and (when remnant marking is on) the usable remnants.
 *
 * Immutable by convention – treat the public properties as read-only.
 */
final class SheetPlan
{
    /** @var Remnant[]|null  null when remnant marking is off. */
    public ?array $remnants = null;

    /** Total area (m²) of the marked remnants; null when remnant marking is off. */
    public ?float $remnantM2 = null;

    /**
     * @param Placement[] $pieces
     */
    public function __construct(
        public int     $index,
        public float   $width,
        public float   $height,
        public array   $pieces,
        public float   $usedPct,
        public CutPlan $cuts,
    ) {
    }

    /** @return array<string, mixed>  One entry of the 1.x $result['sheets']. */
    public function toLegacyArray(): array
    {
        $out = [
            'sheet_index'   => $this->index,
            'stock_width'   => $this->width,
            'stock_height'  => $this->height,
            'pieces'        => array_map(fn(Placement $p) => $p->toLegacyArray(), $this->pieces),
            'used_area_pct' => $this->usedPct,
            'cuts'          => $this->cuts->toLegacyArray(),
        ];
        if ($this->remnants !== null) {
            $out['remnants']        = array_map(fn(Remnant $r) => $r->toLegacyArray(), $this->remnants);
            $out['remnant_area_m2'] = $this->remnantM2;
        }
        return $out;
    }
}
