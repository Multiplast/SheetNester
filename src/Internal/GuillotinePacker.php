<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Internal;

/**
 * Greedy guillotine packer – Best Area Fit placement with Longer Axis Split
 * and lookahead format selection when a new sheet has to be opened.
 *
 * pack() works on a private copy of the stock inventory, so one instance can
 * run any number of piece orderings independently.
 *
 * @internal
 */
final class GuillotinePacker
{
    /**
     * How many upcoming pieces scoreLookahead() simulates when choosing the
     * format for a new sheet. This is a PROTECTIVE bound for pathologically
     * large inputs, not a quality knob: benchmarking showed that a low cap
     * (25) changes format choices and regresses material usage on real-sized
     * orders, so the value is set high enough to never bind for typical
     * orders (tens to low hundreds of pieces).
     */
    private const LOOKAHEAD_PIECES = 250;

    /**
     * @param StockFormat[] $stockFormats Registered formats; never mutated.
     */
    public function __construct(
        private float $kerf,
        private bool  $allowRotation,
        private array $stockFormats,
    ) {
    }

    /**
     * Runs one complete greedy nesting pass.
     *
     * When a new sheet must be opened, openNewSheet() uses a lookahead score to
     * choose the best-fitting format from all available formats (sorted by
     * utilisation ratio; ties broken by area ascending = prefer smaller).
     *
     * @param  array<int, array{float, float}> $allPieces
     * @return array{Sheet[], array}           [sheets, unplaced]
     */
    public function pack(array $allPieces): array
    {
        // Deep-copy format inventory so each run is fully independent
        $formats = [];
        foreach ($this->stockFormats as $fmt) {
            $copy            = new StockFormat($fmt->width, $fmt->height, $fmt->stock);
            $copy->remaining = $fmt->stock;
            $formats[]       = $copy;
        }

        $sheets   = [];
        $unplaced = [];

        foreach ($allPieces as $idx => [$pw, $ph]) {
            if (!$this->placeOnExisting($sheets, $pw, $ph)) {
                if (!$this->openNewSheet($sheets, $formats, $pw, $ph, $allPieces, $idx + 1)) {
                    $reason = $this->diagnoseFailure($pw, $ph, $formats);
                    $found  = false;
                    foreach ($unplaced as &$u) {
                        if ($u['width'] === $pw && $u['height'] === $ph) {
                            $u['qty']++;
                            $found = true;
                            break;
                        }
                    }
                    unset($u);
                    if (!$found) {
                        $unplaced[] = [
                            'width'  => $pw,
                            'height' => $ph,
                            'qty'    => 1,
                            'reason' => $reason,
                        ];
                    }
                }
            }
        }

        return [$sheets, $unplaced];
    }

    /**
     * Attempts to place a piece on one of the already-opened sheets (Best Area Fit).
     *
     * @param Sheet[] $sheets
     */
    public function placeOnExisting(array &$sheets, float $pw, float $ph): bool
    {
        $bestSheet   = null;
        $bestRect    = null;
        $bestArea    = PHP_FLOAT_MAX;
        $bestRotated = false;

        foreach ($sheets as $sheet) {
            [$rect, $rotated] = $this->findBestRect($sheet, $pw, $ph);
            if ($rect !== null && $rect->area() < $bestArea) {
                $bestArea    = $rect->area();
                $bestSheet   = $sheet;
                $bestRect    = $rect;
                $bestRotated = $rotated;
            }
        }

        if ($bestSheet !== null && $bestRect !== null) {
            $this->placePiece($bestSheet, $bestRect, $pw, $ph, $bestRotated);
            return true;
        }

        return false;
    }

    /**
     * Deep copy of a sheet (pieces + freeRects); the StockFormat reference is
     * shared intentionally – elimination never mutates formats, and stock
     * accounting is derived arithmetically from the sheets afterwards.
     */
    public function cloneSheet(Sheet $sheet): Sheet
    {
        $copy = new Sheet($sheet->index, $sheet->format);

        $copy->pieces = [];
        foreach ($sheet->pieces as $p) {
            $copy->pieces[] = new PlacedPiece($p->x, $p->y, $p->width, $p->height, $p->rotated);
        }

        $copy->freeRects = [];
        foreach ($sheet->freeRects as $r) {
            $copy->freeRects[] = new FreeRect($r->x, $r->y, $r->width, $r->height);
        }

        return $copy;
    }

    /**
     * Opens a new sheet for the given piece.
     *
     * For each fitting format a lookahead simulation estimates how well the
     * current piece + remaining pieces would fill it. The format with the
     * highest utilisation ratio (placed_area / format_area) is selected.
     * Ties (within 0.1 %) are broken by area ascending (prefer smaller sheet).
     *
     * Because PHP arrays of objects share the same instances, modifying
     * $format->remaining on a sorted copy also updates the original $formats.
     *
     * @param Sheet[]                         $sheets
     * @param StockFormat[]                   $formats   Mutable inventory copies.
     * @param array<int, array{float, float}> $allPieces All pieces of the pass.
     * @param int                             $fromIdx   Index of the first not-yet-processed piece.
     */
    private function openNewSheet(array &$sheets, array &$formats, float $pw, float $ph, array $allPieces, int $fromIdx): bool
    {
        $candidates = [];

        foreach ($formats as $format) {
            if ($format->remaining <= 0) {
                continue;
            }

            $rotated    = false;
            $fitsNormal = ($pw <= $format->width) && ($ph <= $format->height);

            if (!$fitsNormal) {
                if (!$this->allowRotation) {
                    continue;
                }
                if (($ph <= $format->width) && ($pw <= $format->height)) {
                    $rotated = true;
                } else {
                    continue;
                }
            }

            $score = $this->scoreLookahead($format, $pw, $ph, $rotated, $allPieces, $fromIdx);

            $candidates[] = ['format' => $format, 'rotated' => $rotated, 'score' => $score];
        }

        if (empty($candidates)) {
            return false;
        }

        // Best utilisation wins; within 0.1 % prefer the smaller format
        usort($candidates, function ($a, $b) {
            if (abs($a['score'] - $b['score']) > 0.001) {
                return $b['score'] <=> $a['score'];
            }
            return $a['format']->area() <=> $b['format']->area();
        });

        $best   = $candidates[0];
        $format = $best['format'];

        $format->remaining--;
        $sheet    = new Sheet(count($sheets), $format);
        $sheets[] = $sheet;

        [$rect, $actualRotated] = $this->findBestRect($sheet, $pw, $ph);
        if ($rect === null) {
            array_pop($sheets);
            $format->remaining++;
            return false;
        }

        $this->placePiece($sheet, $rect, $pw, $ph, $actualRotated ?? $best['rotated']);
        return true;
    }

    /**
     * Simulates filling a fresh copy of $format with the current piece and
     * up to LOOKAHEAD_PIECES upcoming pieces (greedy, no commitment).
     * Iterates $allPieces from $fromIdx directly – no array copying in the
     * hot loop.
     *
     * Returns placed_area / format_area  (utilisation ratio, 0–1).
     *
     * @param array<int, array{float, float}> $allPieces
     */
    private function scoreLookahead(
        StockFormat $format,
        float       $pw,
        float       $ph,
        bool        $rotated,
        array       $allPieces,
        int         $fromIdx
    ): float {
        $tmp = new Sheet(0, $format);

        [$rect, $r] = $this->findBestRect($tmp, $pw, $ph);
        if ($rect === null) {
            return 0.0;
        }
        $this->placePiece($tmp, $rect, $pw, $ph, $r ?? $rotated);
        $placed = $pw * $ph;

        $limit = min(count($allPieces), $fromIdx + self::LOOKAHEAD_PIECES);
        for ($i = $fromIdx; $i < $limit; $i++) {
            [$rpw, $rph] = $allPieces[$i];
            [$rect, $rr] = $this->findBestRect($tmp, $rpw, $rph);
            if ($rect !== null) {
                $this->placePiece($tmp, $rect, $rpw, $rph, $rr);
                $placed += $rpw * $rph;
            }
        }

        return $format->area() > 0 ? $placed / $format->area() : 0.0;
    }

    /**
     * Finds the best free rectangle (Best Area Fit) in a sheet for the given piece.
     *
     * @return array{FreeRect|null, bool}  [bestRect, rotated]
     */
    private function findBestRect(Sheet $sheet, float $pw, float $ph): array
    {
        $bestRect    = null;
        $bestArea    = PHP_FLOAT_MAX;
        $bestRotated = false;

        foreach ($sheet->freeRects as $rect) {
            // Fit check uses raw piece dimensions (no kerf).
            // Kerf is only subtracted from remaining free space during guillotine split.
            if ($pw <= $rect->width && $ph <= $rect->height) {
                if ($rect->area() < $bestArea) {
                    $bestArea    = $rect->area();
                    $bestRect    = $rect;
                    $bestRotated = false;
                }
            }
            if ($this->allowRotation) {
                if ($ph <= $rect->width && $pw <= $rect->height) {
                    if ($rect->area() < $bestArea) {
                        $bestArea    = $rect->area();
                        $bestRect    = $rect;
                        $bestRotated = true;
                    }
                }
            }
        }

        return [$bestRect, $bestRotated];
    }

    /**
     * Places a piece into a free rectangle using Guillotine Cut (Longer Axis Split).
     */
    private function placePiece(Sheet $sheet, FreeRect $usedRect, float $pw, float $ph, bool $rotated): void
    {
        $actualW = $rotated ? $ph : $pw;
        $actualH = $rotated ? $pw : $ph;
        $footW   = $actualW + $this->kerf;
        $footH   = $actualH + $this->kerf;

        $sheet->pieces[] = new PlacedPiece($usedRect->x, $usedRect->y, $actualW, $actualH, $rotated);

        $sheet->freeRects = array_values(array_filter(
            $sheet->freeRects,
            fn(FreeRect $r) => $r !== $usedRect
        ));

        $rightW  = $usedRect->width  - $footW;
        $bottomH = $usedRect->height - $footH;

        // The primary cut runs the full length of the used rect and consumes its
        // kerf strip entirely; the secondary free rect therefore spans only the
        // piece's actual dimension, never the primary cut's kerf strip.
        if ($rightW >= $bottomH) {
            if ($rightW > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x + $footW, $usedRect->y, $rightW, $usedRect->height
                );
            }
            if ($bottomH > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x, $usedRect->y + $footH, $actualW, $bottomH
                );
            }
        } else {
            if ($bottomH > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x, $usedRect->y + $footH, $usedRect->width, $bottomH
                );
            }
            if ($rightW > 0) {
                $sheet->freeRects[] = new FreeRect(
                    $usedRect->x + $footW, $usedRect->y, $rightW, $actualH
                );
            }
        }
    }

    /**
     * Determines why a piece could not be placed given a specific format list.
     *
     * @param StockFormat[] $formats
     */
    private function diagnoseFailure(float $pw, float $ph, array $formats): string
    {
        // Dimensional fit is checked first, regardless of stock: a piece that
        // fits some (exhausted) format failed because of stock, not size.
        foreach ($formats as $fmt) {
            $fitsNormal  = ($pw <= $fmt->width)  && ($ph <= $fmt->height);
            $fitsRotated = ($ph <= $fmt->width)  && ($pw <= $fmt->height);
            if ($fitsNormal || ($this->allowRotation && $fitsRotated)) {
                return 'Nedostatek skladových zásob.';
            }
        }
        return 'Kus je větší než všechny dostupné formáty desek.';
    }
}
