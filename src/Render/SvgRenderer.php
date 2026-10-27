<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Render;

use Multiplast\SheetNester\Result\SheetPlan;

/**
 * SVG preview of one sheet plan: coloured pieces with their dimensions (and
 * label), hatched when rotated, usable remnants outlined in green.
 *
 * Texts are English by default; pass SvgRenderer::LABELS_CS (or your own map
 * with the same keys) to translate. The output is well-formed XML, so it can
 * be saved as a standalone .svg file or inlined into HTML.
 */
final class SvgRenderer
{
    public const LABELS_EN = [
        'sheet'       => 'Sheet',
        'utilization' => 'Utilization',
        'remnant'     => 'offcut',
        'rotated'     => '↺ = rotated by 90°',
    ];

    public const LABELS_CS = [
        'sheet'       => 'Deska',
        'utilization' => 'Využití',
        'remnant'     => 'zbytek',
        'rotated'     => '↺ = otočeno o 90°',
    ];

    private const COLORS = [
        '#4E9AF1','#F1A94E','#E45C3A','#5DBB63','#9B59B6',
        '#1ABC9C','#E74C3C','#3498DB','#F39C12','#2ECC71',
        '#E67E22','#16A085','#8E44AD','#D35400','#27AE60',
    ];

    /** @var array<string, string> */
    private array $labels;

    /**
     * @param array<string, string> $labels          Text overrides, see LABELS_EN for the keys.
     * @param bool                  $showPieceLabels Draw the piece label under its dimensions.
     */
    public function __construct(array $labels = [], private bool $showPieceLabels = true)
    {
        $this->labels = $labels + self::LABELS_EN;
    }

    public function render(SheetPlan $sheet): string
    {
        $sheetIndex = $sheet->index;
        $sw         = $sheet->width;
        $sh         = $sheet->height;

        $scale  = min(900 / $sw, 600 / $sh);
        $svgW   = (int) round($sw * $scale);
        $svgH   = (int) round($sh * $scale);
        $padTop = 30;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
            . 'viewBox="0 0 %d %d" preserveAspectRatio="xMidYMid meet" '
            . 'style="background:#f0f0f0;font-family:Arial,sans-serif;">',
            $svgW,
            $svgH + $padTop,
            $svgW,
            $svgH + $padTop
        );

        // &#160; (no-break space) keeps the spacing of the 1.x header and is valid XML.
        $svg .= sprintf(
            '<text x="8" y="20" font-size="13" fill="#333" font-weight="bold">'
            . '%s #%d &#160; %g × %g mm &#160;|&#160; %s: %s%%'
            . '</text>',
            $this->e($this->labels['sheet']),
            $sheetIndex + 1,
            $sw,
            $sh,
            $this->e($this->labels['utilization']),
            $sheet->usedPct
        );

        $svg .= sprintf(
            '<rect x="0" y="%d" width="%d" height="%d" fill="white" stroke="#999" stroke-width="1.5"/>',
            $padTop, $svgW, $svgH
        );

        foreach ($sheet->pieces as $i => $p) {
            $color = self::COLORS[$i % count(self::COLORS)];
            $x     = (int) round($p->x * $scale);
            $y     = (int) round($p->y * $scale) + $padTop;
            $rw    = (int) round($p->width * $scale);
            $rh    = (int) round($p->height * $scale);

            $fill = $color;
            if ($p->rotated) {
                $patId = "hatch{$sheetIndex}_{$i}";
                $svg .= sprintf(
                    '<defs><pattern id="%s" patternUnits="userSpaceOnUse" width="8" height="8">'
                    . '<rect width="8" height="8" fill="%s"/>'
                    . '<line x1="0" y1="8" x2="8" y2="0" stroke="rgba(0,0,0,0.18)" stroke-width="1.5"/>'
                    . '</pattern></defs>',
                    $patId, $color
                );
                $fill = "url(#{$patId})";
            }

            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" fill="%s" '
                . 'stroke="#fff" stroke-width="1.5" opacity="0.88"/>',
                $x, $y, $rw, $rh, $fill
            );

            $label    = sprintf('%g×%g', $p->width, $p->height) . ($p->rotated ? ' ↺' : '');
            $fontSize = max(8, min(13, (int) ($rw / max(1, strlen($label)) * 1.6)));
            if ($rw > 20 && $rh > 14) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-size="%d" fill="#fff" text-anchor="middle" '
                    . 'dominant-baseline="middle" font-weight="bold" '
                    . 'style="text-shadow:0 1px 2px rgba(0,0,0,.4)">%s</text>',
                    $x + (int) ($rw / 2),
                    $y + (int) ($rh / 2),
                    $fontSize,
                    $this->e($label)
                );

                // Piece label on a second line when there is room for it
                if ($this->showPieceLabels && $p->label !== null && $p->label !== ''
                    && $rh > 2 * $fontSize + 10 && $rw > 40) {
                    $labelSize = max(8, min(11, (int) ($rw / max(1, mb_strlen($p->label)) * 1.4)));
                    $svg .= sprintf(
                        '<text x="%d" y="%d" font-size="%d" fill="#fff" text-anchor="middle" '
                        . 'dominant-baseline="middle" '
                        . 'style="text-shadow:0 1px 2px rgba(0,0,0,.4)">%s</text>',
                        $x + (int) ($rw / 2),
                        $y + (int) ($rh / 2) + $fontSize + 2,
                        $labelSize,
                        $this->e($p->label)
                    );
                }
            }
        }

        // Usable remnants – grey like waste, dashed green outline + dimensions
        foreach (($sheet->remnants ?? []) as $r) {
            $x  = (int) round($r->x * $scale);
            $y  = (int) round($r->y * $scale) + $padTop;
            $rw = (int) round($r->width * $scale);
            $rh = (int) round($r->height * $scale);

            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" fill="rgba(46,125,50,0.08)" '
                . 'stroke="#2e7d32" stroke-width="1.5" stroke-dasharray="6,4"/>',
                $x, $y, $rw, $rh
            );

            $label    = sprintf('%s %g×%g', $this->labels['remnant'], $r->width, $r->height);
            $fontSize = max(8, min(13, (int) ($rw / max(1, strlen($label)) * 1.6)));
            if ($rw > 30 && $rh > 14) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-size="%d" fill="#2e7d32" text-anchor="middle" '
                    . 'dominant-baseline="middle" font-weight="bold">%s</text>',
                    $x + (int) ($rw / 2),
                    $y + (int) ($rh / 2),
                    $fontSize,
                    $this->e($label)
                );
            }
        }

        if (array_filter($sheet->pieces, fn($p) => $p->rotated)) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-size="10" fill="#666">%s</text>',
                $svgW - 120,
                $svgH + $padTop - 5,
                $this->e($this->labels['rotated'])
            );
        }

        $svg .= '</svg>';
        return $svg;
    }

    /** XML-escapes text content (explicit flags – identical on every PHP version). */
    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
