<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Render;

/**
 * SVG preview of one sheet, drawn from the result array of
 * SheetNester::calculate() (one entry of $result['sheets']).
 *
 * @internal Signature will change with the 2.0 result object; use SheetNester::toSvg().
 */
final class SvgRenderer
{
    private const COLORS = [
        '#4E9AF1','#F1A94E','#E45C3A','#5DBB63','#9B59B6',
        '#1ABC9C','#E74C3C','#3498DB','#F39C12','#2ECC71',
        '#E67E22','#16A085','#8E44AD','#D35400','#27AE60',
    ];

    /**
     * @param array<string, mixed> $sheet      One entry of $result['sheets'].
     * @param int                  $sheetIndex Zero-based index (shown as #index+1).
     */
    public function render(array $sheet, int $sheetIndex): string
    {
        $sw     = $sheet['stock_width'];
        $sh     = $sheet['stock_height'];

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

        $usedPct = $sheet['used_area_pct'];
        $svg .= sprintf(
            '<text x="8" y="20" font-size="13" fill="#333" font-weight="bold">'
            . 'Deska #%d &nbsp; %g × %g mm &nbsp;|&nbsp; Využití: %s%%'
            . '</text>',
            $sheetIndex + 1,
            $sw,
            $sh,
            $usedPct
        );

        $svg .= sprintf(
            '<rect x="0" y="%d" width="%d" height="%d" fill="white" stroke="#999" stroke-width="1.5"/>',
            $padTop, $svgW, $svgH
        );

        foreach ($sheet['pieces'] as $i => $p) {
            $color = self::COLORS[$i % count(self::COLORS)];
            $x     = (int) round($p['x'] * $scale);
            $y     = (int) round($p['y'] * $scale) + $padTop;
            $rw    = (int) round($p['width'] * $scale);
            $rh    = (int) round($p['height'] * $scale);

            $fill = $color;
            if ($p['rotated']) {
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

            $label    = sprintf('%g×%g', $p['width'], $p['height']) . ($p['rotated'] ? ' ↺' : '');
            $fontSize = max(8, min(13, (int) ($rw / max(1, strlen($label)) * 1.6)));
            if ($rw > 20 && $rh > 14) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-size="%d" fill="#fff" text-anchor="middle" '
                    . 'dominant-baseline="middle" font-weight="bold" '
                    . 'style="text-shadow:0 1px 2px rgba(0,0,0,.4)">%s</text>',
                    $x + (int) ($rw / 2),
                    $y + (int) ($rh / 2),
                    $fontSize,
                    htmlspecialchars($label)
                );
            }
        }

        // Usable remnants – grey like waste, dashed green outline + dimensions
        foreach (($sheet['remnants'] ?? []) as $r) {
            $x  = (int) round($r['x'] * $scale);
            $y  = (int) round($r['y'] * $scale) + $padTop;
            $rw = (int) round($r['width'] * $scale);
            $rh = (int) round($r['height'] * $scale);

            $svg .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d" fill="rgba(46,125,50,0.08)" '
                . 'stroke="#2e7d32" stroke-width="1.5" stroke-dasharray="6,4"/>',
                $x, $y, $rw, $rh
            );

            $label    = sprintf('zbytek %g×%g', $r['width'], $r['height']);
            $fontSize = max(8, min(13, (int) ($rw / max(1, strlen($label)) * 1.6)));
            if ($rw > 30 && $rh > 14) {
                $svg .= sprintf(
                    '<text x="%d" y="%d" font-size="%d" fill="#2e7d32" text-anchor="middle" '
                    . 'dominant-baseline="middle" font-weight="bold">%s</text>',
                    $x + (int) ($rw / 2),
                    $y + (int) ($rh / 2),
                    $fontSize,
                    htmlspecialchars($label)
                );
            }
        }

        if (array_filter($sheet['pieces'], fn($p) => $p['rotated'])) {
            $svg .= sprintf(
                '<text x="%d" y="%d" font-size="10" fill="#666">↺ = otočeno o 90°</text>',
                $svgW - 120,
                $svgH + $padTop - 5
            );
        }

        $svg .= '</svg>';
        return $svg;
    }
}
