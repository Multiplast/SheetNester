<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

/**
 * SheetNesterPdf
 *
 * Generates a professional PDF cutting plan report from SheetNester::calculate() output.
 * The visual style follows the reference format:
 *   - Page 1   : Summary (stats, format list, full piece list)
 *   - Page 2+  : Sheet diagrams, 2 per A4 portrait page
 *                Each diagram shows: grey waste background, white piece rectangles,
 *                blue dimension labels inside each piece, blue [N] index in piece centre.
 *
 * Requirements: composer require mpdf/mpdf (optional dependency of this package –
 * only needed when generating PDFs, SheetNester itself has no dependencies).
 * Framework-agnostic (Nette friendly).
 *
 * Usage:
 *   $pdf = new SheetNesterPdf($result, [
 *       'title'         => 'Nářezový plán č.12345',
 *       'material'      => 'PC deska 2W 10 mm čirá',
 *       'company_name'  => 'TITAN – MULTIPLAST s.r.o.',
 *       'company_addr'  => 'Jablonecká 1379, 468 51 Smržovka',
 *       'company_phone' => '+420 483 360 060',
 *       'company_email' => 'info@titan-multiplast.cz',
 *       'company_www'   => 'www.titan-multiplast.cz',
 *       'company_ico'   => '25022229',
 *       'company_dic'   => 'CZ25022229',
 *   ]);
 *   $pdf->download('narezovy-plan.pdf');
 */
class SheetNesterPdf
{
    // -------------------------------------------------------------------------
    // Layout constants (all in mm, for SVG viewBox units = mm)
    // -------------------------------------------------------------------------

    /** Maximum diagram width inside the page (A4 portrait, 15mm side margins). */
    private const DIAGRAM_MAX_W = 175.0;

    /** Maximum diagram height per sheet block (2 blocks per page, headers included). */
    private const DIAGRAM_MAX_H = 90.0;

    // -------------------------------------------------------------------------
    // Colour palette
    // -------------------------------------------------------------------------

    private const COL_WASTE       = '#c8c8c8';  // grey fill for unused sheet area
    private const COL_PIECE_FILL  = '#ffffff';  // piece rectangle fill
    private const COL_PIECE_STROKE = '#444444'; // piece border
    private const COL_SHEET_STROKE = '#555555'; // outer sheet border
    private const COL_DIM         = '#0033cc';  // dimension labels & index text
    private const COL_HEADER_BG   = '#f4f4f4';
    private const COL_HEADER_BORD = '#aaaaaa';
    private const COL_TH_BG       = '#e4e4e4';
    private const COL_REMNANT     = '#2e7d32';  // usable remnant outline + dimension labels

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    /** @var array Result array from SheetNester::calculate() */
    private array $result;

    /** @var array Report metadata */
    private array $meta;

    /**
     * @param array $result  Output of SheetNester::calculate().
     * @param array $meta    Report metadata (all keys optional):
     *                       - title         : Order title / plan number
     *                       - subtitle      : Optional subtitle (e.g. customer name)
     *                       - material      : Material description
     *                       - company_name  : Company name
     *                       - company_addr  : Address (single line)
     *                       - company_phone : Phone number
     *                       - company_email : E-mail address
     *                       - company_www   : Website
     *                       - company_ico   : IČO
     *                       - company_dic   : DIČ / IČ DPH
     */
    public function __construct(array $result, array $meta = [])
    {
        $this->result = $result;
        $this->meta   = array_merge([
            'title'         => 'Nářezový plán',
            'subtitle'      => '',
            'material'      => '',
            'company_name'  => '',
            'company_addr'  => '',
            'company_phone' => '',
            'company_email' => '',
            'company_www'   => '',
            'company_ico'   => '',
            'company_dic'   => '',
        ], $meta);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the PDF as a binary string (e.g. to store in a variable or stream).
     */
    public function generate(): string
    {
        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML($this->buildHtml());
        return $mpdf->Output('', 'S');
    }

    /**
     * Saves the PDF to a file on disk.
     */
    public function save(string $path): void
    {
        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML($this->buildHtml());
        $mpdf->Output($path, 'F');
    }

    /**
     * Sends the PDF to the browser as a download.
     */
    public function download(string $filename = 'narezovy-plan.pdf'): void
    {
        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML($this->buildHtml());
        $mpdf->Output($filename, 'D');
    }

    /**
     * Sends the PDF to the browser for inline display.
     */
    public function inline(string $filename = 'narezovy-plan.pdf'): void
    {
        $mpdf = $this->createMpdf();
        $mpdf->WriteHTML($this->buildHtml());
        $mpdf->Output($filename, 'I');
    }

    // -------------------------------------------------------------------------
    // mPDF instance
    // -------------------------------------------------------------------------

    private function createMpdf(): \Mpdf\Mpdf
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new \LogicException(
                'SheetNesterPdf requires mPDF – install it with: composer require mpdf/mpdf'
            );
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'           => 'utf-8',
            'format'         => 'A4',
            'orientation'    => 'P',
            'margin_top'     => 12,
            'margin_bottom'  => 12,
            'margin_left'    => 15,
            'margin_right'   => 15,
            'default_font'   => 'dejavusans',
            'default_font_size' => 9,
        ]);

        $mpdf->SetTitle($this->meta['title']);
        $mpdf->SetAuthor($this->meta['company_name']);

        // Footer: page number + company
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="font-size:7pt;color:#888;border-top:0.3mm solid #ccc;padding-top:1mm">'
            . '<tr><td>' . $this->e($this->meta['company_name']) . '</td>'
            . '<td align="right">{PAGENO}/{nbpg}</td></tr></table>'
        );

        return $mpdf;
    }

    // -------------------------------------------------------------------------
    // HTML assembly
    // -------------------------------------------------------------------------

    private function buildHtml(): string
    {
        $html  = $this->buildStyles();
        $html .= $this->buildSummaryPage();

        // Sheet diagrams flow directly after the summary – no forced page break.
        // Each block is unbreakable (page-break-inside: avoid on .sheet-block),
        // so mPDF moves whole blocks to the next page when they don't fit.
        // $offset = global piece index so diagram [N] matches the summary table.
        $offset = 1;
        foreach ($this->result['sheets'] as $sheet) {
            $html .= $this->buildSheetBlock($sheet, $offset);
            $html .= '<div class="sheet-sep"></div>';
            $offset += count($sheet['pieces']);
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    private function buildStyles(): string
    {
        return <<<'CSS'
        <style>
            * { box-sizing: border-box; }
            body  { font-family: dejavusans, sans-serif; font-size: 9pt; color: #222; }

            /* ---- Summary page ---- */
            .report-title  { font-size: 13pt; font-weight: bold; margin: 0; }
            .report-sub    { font-size: 10pt; color: #444; margin: 1mm 0 2mm; }
            .co-header     { font-size: 7.5pt; color: #555; text-align: right; line-height: 1.5; }
            .co-name       { font-weight: bold; font-size: 8.5pt; }
            hr.divider     { border: none; border-top: 0.4mm solid #bbb; margin: 3mm 0; }

            .stat-wrap     { margin-bottom: 4mm; }
            .stat-grid     { width: 100%; border-collapse: collapse; }
            .stat-grid td  { padding: 0.8mm 2mm; font-size: 12pt; width: 25%; }
            .stat-label    { color: #666; }
            .stat-val      { font-weight: bold; }

            .section-head  { font-size: 9.5pt; font-weight: bold; margin: 3mm 0 1.5mm; color: #222; }

            table.plan-tbl { width: 100%; border-collapse: collapse; margin-bottom: 4mm; font-size: 8pt; }
            table.plan-tbl th {
                background: #e4e4e4; padding: 1.5mm 2mm;
                border: 0.25mm solid #bbb; text-align: left; font-size: 8pt;
            }
            table.plan-tbl td {
                padding: 1.2mm 2mm; border: 0.25mm solid #ddd;
            }
            table.plan-tbl tr.even td { background: #f8f8f8; }
            .warn  { color: #cc0000; font-weight: bold; }

            /* ---- Sheet header block ---- */
            .sheet-block   { margin-bottom: 2mm; page-break-inside: avoid; }
            table.sh-hdr   {
                width: 100%; border-collapse: collapse;
                border: 0.35mm solid #aaa; background: #f4f4f4;
                margin-bottom: 1.5mm;
            }
            .sh-num {
                font-size: 20pt; font-weight: bold; text-align: center;
                width: 14mm; padding: 2mm; border-right: 0.35mm solid #aaa;
                vertical-align: middle;
            }
            .sh-info { padding: 1.5mm 3mm; vertical-align: top; }
            .sh-info .main { font-weight: bold; font-size: 9pt; }
            .sh-info .sub  { font-size: 8pt; color: #444; }
            .sh-right {
                text-align: right; vertical-align: top;
                padding: 1.5mm 3mm; font-size: 8pt; white-space: nowrap; width: 28mm;
            }
            .sh-util { font-weight: bold; font-size: 10pt; }
            .sh-stats {
                font-size: 7.5pt; color: #444;
                padding: 1mm 3mm; border-top: 0.25mm solid #ccc;
            }

            /* ---- Diagram ---- */
            .diagram-wrap  { text-align: center; }
            .sheet-sep     { height: 5mm; }
        </style>
        CSS;
    }

    // -------------------------------------------------------------------------
    // Summary page
    // -------------------------------------------------------------------------

    private function buildSummaryPage(): string
    {
        $m = $this->meta;
        $r = $this->result;

        // Compute aggregate areas
        $totalPieceArea = 0.0;
        $totalSheetArea = 0.0;
        foreach ($r['sheets'] as $s) {
            $totalSheetArea += ($s['stock_width'] * $s['stock_height']) / 1_000_000;
            foreach ($s['pieces'] as $p) {
                $totalPieceArea += ($p['width'] * $p['height']) / 1_000_000;
            }
        }
        $wasteArea  = max(0.0, $totalSheetArea - $totalPieceArea);
        $utilRelPct = $totalSheetArea > 0
            ? round($totalPieceArea / $totalSheetArea * 100, 2)
            : 0.0;

        $html = '';

        // ---- Title + company header ----
        $html .= '<table width="100%"><tr>';
        $html .= '<td style="vertical-align:top">';
        $html .= '<div class="report-title">' . $this->e($m['title']) . '</div>';
        if ($m['subtitle']) {
            $html .= '<div class="report-sub">' . $this->e($m['subtitle']) . '</div>';
        }
        if ($m['material']) {
            $html .= '<div style="font-size:9pt;margin-top:1mm">Materiál: <strong>'
                   . $this->e($m['material']) . '</strong></div>';
        }
        $html .= '</td>';

        if ($m['company_name']) {
            $html .= '<td class="co-header">';
            $html .= '<span class="co-name">' . $this->e($m['company_name']) . '</span><br>';
            if ($m['company_addr'])  $html .= $this->e($m['company_addr']) . '<br>';
            $parts = array_filter([$m['company_phone'], $m['company_email'], $m['company_www']]);
            if ($parts) $html .= implode(' | ', array_map([$this, 'e'], $parts)) . '<br>';
            $ico = array_filter(['IČO: ' . $m['company_ico'], 'DIČ: ' . $m['company_dic']]);
            if ($ico)   $html .= implode(' | ', $ico);
            $html .= '</td>';
        }
        $html .= '</tr></table>';
        $html .= '<hr class="divider">';

        // ---- Statistics grid ----
        $unplacedQty = array_sum(array_column($r['unplaced_pieces'] ?? [], 'qty'));
        $html .= '<div class="stat-wrap"><table class="stat-grid"><tr>';
        $stats = [
            'Uložené dílce'    => round($totalPieceArea, 4) . ' m²',
            'Odpad'            => round($wasteArea, 4)      . ' m²',
            'Využití materiálu'=> $utilRelPct               . ' %',
            'Celkem desek'     => $r['total_sheets']        . ' ks',
            'Strategie'        => $r['strategy']            ?? '—',
            'Čas výpočtu'      => ($r['calculation_time_ms'] ?? '—') . ' ms',
            'Testované průchody'=> ($r['permutations_tested'] ?? '—'),
            'Neumístěné kusy'  => $unplacedQty > 0
                ? '<span class="warn">' . $unplacedQty . ' ks</span>'
                : '0 ks',
        ];
        $col = 0;
        foreach ($stats as $label => $val) {
            $html .= '<td class="stat-label">' . $this->e($label) . ':</td>'
                   . '<td class="stat-val">'   . $val             . '</td>';
            if (++$col % 4 === 0 && $col < count($stats)) {
                $html .= '</tr><tr>';
            }
        }
        $html .= '</tr></table></div>';

        // ---- Used formats table ----
        $html .= '<div class="section-head">Použité formáty</div>';
        $html .= '<table class="plan-tbl"><tr>'
               . '<th>Délka (mm)</th><th>Šířka (mm)</th><th>Plocha (m²)</th>'
               . '<th>Použito</th><th>Zbývá na skladě</th>'
               . '</tr>';
        foreach ($r['stock_usage'] as $i => $u) {
            $area  = round(($u['width'] * $u['height'] * $u['used']) / 1_000_000, 4);
            $class = $i % 2 === 1 ? ' class="even"' : '';
            $html .= "<tr{$class}>"
                   . '<td>' . $this->fmtNum($u['height'])    . ' mm</td>'
                   . '<td>' . $this->fmtNum($u['width'])     . ' mm</td>'
                   . '<td>' . $area                          . ' m²</td>'
                   . '<td>' . $u['used']                     . ' ks</td>'
                   . '<td>' . $u['remaining']                . ' ks</td>'
                   . '</tr>';
        }
        $html .= '</table>';

        // ---- Piece list table — grouped by dimensions ----
        // Pieces with identical width × height are merged into one row.
        // Key = "width:height", value = aggregated info.

        /** @var array<string, array{height:float, width:float, qty:int, area:float, sheets:int[], rotated:bool}> $groups */
        $groups = [];
        foreach ($r['sheets'] as $sheet) {
            foreach ($sheet['pieces'] as $p) {
                // Normalise key: always smaller dimension first so that
                // rotated and non-rotated variants of the same cut are NOT merged
                // (they differ visually on grain-direction material).
                $key = $p['width'] . ':' . $p['height'];
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'height'  => $p['height'],
                        'width'   => $p['width'],
                        'qty'     => 0,
                        'area'    => 0.0,
                        'sheets'  => [],
                        'rotated' => $p['rotated'],
                    ];
                }
                $groups[$key]['qty']++;
                $groups[$key]['area'] += ($p['width'] * $p['height']) / 1_000_000;
                $sheetNum = $sheet['sheet_index'] + 1;
                if (!in_array($sheetNum, $groups[$key]['sheets'], true)) {
                    $groups[$key]['sheets'][] = $sheetNum;
                }
            }
        }

        $html .= '<div class="section-head">Dílce a odřezky</div>';
        $html .= '<table class="plan-tbl"><tr>'
               . '<th>#</th><th>Délka (mm)</th><th>Šířka (mm)</th>'
               . '<th>Plocha (m²)</th><th>Množství</th><th>Otočeno</th><th>Plán (deska)</th>'
               . '</tr>';
        $rowIdx = 1;
        foreach ($groups as $group) {
            sort($group['sheets']);
            $class = $rowIdx % 2 === 0 ? ' class="even"' : '';
            $html .= "<tr{$class}>"
                   . '<td>' . $rowIdx                                           . '</td>'
                   . '<td>' . $this->fmtNum($group['height'])                   . ' mm</td>'
                   . '<td>' . $this->fmtNum($group['width'])                    . ' mm</td>'
                   . '<td>' . round($group['area'], 4)                          . ' m²</td>'
                   . '<td>' . $group['qty']                                     . ' ks</td>'
                   . '<td>' . ($group['rotated'] ? '↺ ano' : '—')              . '</td>'
                   . '<td>' . implode(', ', $group['sheets'])                   . '</td>'
                   . '</tr>';
            $rowIdx++;
        }
        $html .= '</table>';

        // ---- Usable remnants (advisory) ----
        // Grouped by dimensions across sheets, mirrors the piece-list grouping.
        $remnantGroups = [];
        foreach ($r['sheets'] as $sheet) {
            foreach (($sheet['remnants'] ?? []) as $rem) {
                $key = $rem['width'] . ':' . $rem['height'];
                if (!isset($remnantGroups[$key])) {
                    $remnantGroups[$key] = [
                        'width'  => $rem['width'],
                        'height' => $rem['height'],
                        'qty'    => 0,
                        'area'   => 0.0,
                        'sheets' => [],
                    ];
                }
                $remnantGroups[$key]['qty']++;
                $remnantGroups[$key]['area'] += ($rem['width'] * $rem['height']) / 1_000_000;
                $sheetNum = $sheet['sheet_index'] + 1;
                if (!in_array($sheetNum, $remnantGroups[$key]['sheets'], true)) {
                    $remnantGroups[$key]['sheets'][] = $sheetNum;
                }
            }
        }

        if (!empty($remnantGroups)) {
            $totalRemnantM2 = $r['total_remnant_m2'] ?? 0.0;
            $html .= '<div class="section-head" style="color:' . self::COL_REMNANT . '">'
                   . 'Použitelné zbytky (celkem ' . $totalRemnantM2 . ' m²)</div>';
            $html .= '<table class="plan-tbl"><tr>'
                   . '<th>#</th><th>Délka (mm)</th><th>Šířka (mm)</th>'
                   . '<th>Plocha (m²)</th><th>Množství</th><th>Plán (deska)</th>'
                   . '</tr>';
            $rowIdx = 1;
            foreach ($remnantGroups as $group) {
                sort($group['sheets']);
                $class = $rowIdx % 2 === 0 ? ' class="even"' : '';
                $html .= "<tr{$class}>"
                       . '<td>' . $rowIdx                                . '</td>'
                       . '<td>' . $this->fmtNum($group['height'])        . ' mm</td>'
                       . '<td>' . $this->fmtNum($group['width'])         . ' mm</td>'
                       . '<td>' . round($group['area'], 4)               . ' m²</td>'
                       . '<td>' . $group['qty']                          . ' ks</td>'
                       . '<td>' . implode(', ', $group['sheets'])        . '</td>'
                       . '</tr>';
                $rowIdx++;
            }
            $html .= '</table>';
            $html .= '<div style="font-size:7pt;color:#888;margin-bottom:3mm">'
                   . 'Orientační — vyznačené zbytky negarantují vyřezatelnost jedním sledem guillotine řezů.'
                   . '</div>';
        }

        // ---- Unplaced pieces ----
        if (!empty($r['unplaced_pieces'])) {
            $html .= '<div class="section-head warn">Neumístěné dílce</div>';
            $html .= '<table class="plan-tbl"><tr>'
                   . '<th>Délka (mm)</th><th>Šířka (mm)</th><th>Množství</th><th>Důvod</th>'
                   . '</tr>';
            foreach ($r['unplaced_pieces'] as $p) {
                $html .= '<tr style="color:#cc0000">'
                       . '<td>' . $this->fmtNum($p['height']) . ' mm</td>'
                       . '<td>' . $this->fmtNum($p['width'])  . ' mm</td>'
                       . '<td>' . $p['qty']                   . ' ks</td>'
                       . '<td>' . $this->e($p['reason'])      . '</td>'
                       . '</tr>';
            }
            $html .= '</table>';
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Sheet block (header + SVG diagram)
    // -------------------------------------------------------------------------

    /**
     * Builds one sheet block (header + diagram).
     *
     * @param array $sheet       Single sheet from result['sheets'].
     * @param int   $pieceOffset Global 1-based index of the first piece on this sheet.
     */
    private function buildSheetBlock(array $sheet, int $pieceOffset): string
    {
        return '<div class="sheet-block">'
             . $this->buildSheetHeader($sheet)
             . '<div class="diagram-wrap">'
             . $this->buildSheetSvg($sheet, $pieceOffset)
             . '</div>'
             . '</div>';
    }

    private function buildSheetHeader(array $sheet): string
    {
        $pieceArea  = 0.0;
        foreach ($sheet['pieces'] as $p) {
            $pieceArea += ($p['width'] * $p['height']) / 1_000_000;
        }
        $sheetArea  = round(($sheet['stock_width'] * $sheet['stock_height']) / 1_000_000, 4);
        $wasteArea  = round(max(0.0, $sheetArea - $pieceArea), 4);
        $sheetNum   = $sheet['sheet_index'] + 1;
        $pieceCnt   = count($sheet['pieces']);
        $dimStr     = $this->fmtNum($sheet['stock_height'])
                    . ' × ' . $this->fmtNum($sheet['stock_width']) . ' mm';

        $remnantInfo = '';
        $remnantCnt  = count($sheet['remnants'] ?? []);
        if ($remnantCnt > 0) {
            $remnantInfo = '&nbsp;|&nbsp;
              Použitelný zbytek: ' . ($sheet['remnant_area_m2'] ?? 0) . ' m² (' . $remnantCnt . ' ks)';
        }

        return '
        <table class="sh-hdr" cellspacing="0" cellpadding="0">
          <tr>
            <td class="sh-num">' . $sheetNum . '</td>
            <td class="sh-info">
              <div class="main">' . $this->e($this->meta['title']) . '</div>
              <div class="sub">Materiál: ' . $this->e($this->meta['material']) . '</div>
              <div class="sub">' . $this->e($dimStr) . '</div>
            </td>
            <td class="sh-right">
              1 ks (' . $sheetArea . ' m²)<br>
              <span class="sh-util">' . $sheet['used_area_pct'] . ' %</span>
            </td>
          </tr>
          <tr>
            <td colspan="3" class="sh-stats">
              Dílce: ' . $pieceCnt . ' ks (' . round($pieceArea, 4) . ' m²)
              &nbsp;|&nbsp;
              Odpad: ' . $wasteArea . ' m²' . $remnantInfo . '
            </td>
          </tr>
        </table>';
    }

    // -------------------------------------------------------------------------
    // SVG diagram
    // -------------------------------------------------------------------------

    /**
     * Builds an inline SVG cutting plan diagram for a single sheet.
     *
     * @param array $sheet       Sheet data from result['sheets'].
     * @param int   $pieceOffset Global 1-based piece index for this sheet's first piece.
     */
    private function buildSheetSvg(array $sheet, int $pieceOffset): string
    {
        $sw = (float) $sheet['stock_width'];
        $sh = (float) $sheet['stock_height'];

        // Always display the longer dimension horizontally so the diagram
        // makes the best use of the page width.
        // When height > width, rotate the display 90°:
        //   display X axis ← stock Y axis (height)
        //   display Y axis ← stock X axis (width)
        $rotate = ($sh > $sw);

        // dw = horizontal span, dh = vertical span in the SVG canvas
        $dw = $rotate ? $sh : $sw;
        $dh = $rotate ? $sw : $sh;

        // Scale so the diagram fits within DIAGRAM_MAX_W × DIAGRAM_MAX_H (mm)
        $scale = min(self::DIAGRAM_MAX_W / $dw, self::DIAGRAM_MAX_H / $dh);

        // SVG canvas dimensions in mm
        $vw = round($dw * $scale, 3);
        $vh = round($dh * $scale, 3);

        // Base font sizes in mm (used for pieces ≥ 300 mm on shortest displayed side).
        // For smaller pieces the font is scaled down proportionally so labels
        // never overflow or overlap inside the piece rectangle.
        $baseDimFont = 4.0;
        $baseIdxFont = 3.64;

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" '
            . 'width="%smm" height="%smm" viewBox="0 0 %.3f %.3f">',
            $vw, $vh, $vw, $vh
        );

        // --- Sheet background (represents waste / unused material) ---
        $svg .= sprintf(
            '<rect x="0" y="0" width="%.3f" height="%.3f" fill="%s" stroke="%s" stroke-width="0.4"/>',
            $vw, $vh, self::COL_WASTE, self::COL_SHEET_STROKE
        );

        // --- Pieces ---
        foreach ($sheet['pieces'] as $localIdx => $p) {
            // When the diagram is rotated (height > width), map axes:
            //   display_x = p.y,      display_y = p.x
            //   display_w = p.height, display_h = p.width
            // Labels always describe what is visible: horizontal label on top,
            // vertical label on the left edge.
            if ($rotate) {
                $px        = round($p['y']      * $scale, 3);
                $py        = round($p['x']      * $scale, 3);
                $pw        = round($p['height'] * $scale, 3);
                $ph        = round($p['width']  * $scale, 3);
                $labelHorz = $this->fmtNum($p['height']);
                $labelVert = $this->fmtNum($p['width']);
            } else {
                $px        = round($p['x']      * $scale, 3);
                $py        = round($p['y']      * $scale, 3);
                $pw        = round($p['width']  * $scale, 3);
                $ph        = round($p['height'] * $scale, 3);
                $labelHorz = $this->fmtNum($p['width']);
                $labelVert = $this->fmtNum($p['height']);
            }

            $globalLabel = $pieceOffset + $localIdx;

            // Clip path keeps all labels inside piece boundaries
            $clipId = 'clip_' . $pieceOffset . '_' . $localIdx;
            $svg .= sprintf(
                '<defs><clipPath id="%s">'
                . '<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"/>'
                . '</clipPath></defs>',
                $clipId, $px, $py, $pw, $ph
            );

            // Piece rectangle
            $svg .= sprintf(
                '<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f" '
                . 'fill="%s" stroke="%s" stroke-width="0.3"/>',
                $px, $py, $pw, $ph,
                self::COL_PIECE_FILL, self::COL_PIECE_STROKE
            );

            $svg .= sprintf('<g clip-path="url(#%s)">', $clipId);

            // Text placement using manually computed text width.
            // mPDF SVG support is limited, so we avoid relying on text-anchor="middle".
            // Instead we compute the approximate rendered width of each string and
            // shift the start x (or cy for rotated text) by half that width ourselves.
            //
            // Average glyph width ratio for DejaVu Sans digits/brackets: ~0.55 em.
            // SVG y is always the BASELINE; visual glyph centre ≈ baseline - fontSize*0.35.
            //
            // Horizontal text centred at target_x:
            //   x_start = target_x - textWidth/2
            //   y       = target_y + fontSize*0.35  (shift baseline so glyphs centre at target_y)
            //
            // Rotated text (-90°) centred at (target_x, target_y):
            //   After rotate(-90, cx, cy) text extends UPWARD from cy.
            //   With text-anchor="start": cy_start = target_y + textWidth/2
            //   Baseline at cx → ascenders reach cx + ~0.7*fontSize to the right.
            //   cx = px + fontSize*0.85  (puts glyph block just inside left border)

            // Scale fonts down for small pieces (shortest displayed side < 300 mm).
            // The threshold of 300 mm in model space maps to min(pw, ph) in SVG mm.
            // We derive the threshold in SVG units and compare with the smaller dimension.
            $minSide       = min($pw, $ph);                // shorter side in SVG mm
            $threshold     = 500 * $scale;                 // 300 mm model → SVG mm
            $fontScale     = $minSide < $threshold
                ? max(0.4, $minSide / $threshold)          // scale down, minimum 40 %
                : 1.0;
            $dimFont = round($baseDimFont * $fontScale, 3);
            $idxFont = round($baseIdxFont * $fontScale, 3);

            $labelHorzW = $this->textWidth($labelHorz, $dimFont);
            $labelVertW = $this->textWidth($labelVert, $dimFont);
            $labelIdxW  = $this->textWidth('[' . $globalLabel . ']', $idxFont);

            // Horizontal label — near top border, manually centred.
            $svg .= sprintf(
                '<text x="%.3f" y="%.3f" font-size="%.2f" fill="%s" '
                . 'text-anchor="start" '
                . 'font-family="DejaVu Sans,sans-serif">%s</text>',
                $px + $pw / 2 - $labelHorzW / 2,   // shift left by half text width
                $py + $dimFont,                      // baseline = 1em below top border
                $dimFont,
                self::COL_DIM,
                $this->e($labelHorz)
            );

            // Vertical label — rotated -90°, manually centred along the height.
            // rotate(-90, cx, cy): text extends upward from cy in screen coords.
            // Centre of text in screen y = cy - textWidth/2  →  cy = ph/2 + textWidth/2.
            $cx = $px + $dimFont * 0.85;              // baseline: glyph block just inside left border
            $cy = $py + $ph / 2 + $labelVertW / 2;   // manual vertical centre
            $svg .= sprintf(
                '<text x="%.3f" y="%.3f" font-size="%.2f" fill="%s" '
                . 'text-anchor="start" '
                . 'font-family="DejaVu Sans,sans-serif" '
                . 'transform="rotate(-90,%.3f,%.3f)">%s</text>',
                $cx, $cy,
                $dimFont,
                self::COL_DIM,
                $cx, $cy,
                $this->e($labelVert)
            );

            // Index [N] — centred in piece, manually.
            $svg .= sprintf(
                '<text x="%.3f" y="%.3f" font-size="%.2f" fill="%s" '
                . 'text-anchor="start" '
                . 'font-family="DejaVu Sans,sans-serif">[%d]</text>',
                $px + $pw / 2 - $labelIdxW / 2,    // manual horizontal centre
                $py + $ph / 2 + $idxFont * 0.35,   // baseline offset for visual centering
                $idxFont,
                self::COL_DIM,
                $globalLabel
            );

            $svg .= '</g>';
        }

        // --- Usable remnants (advisory) ---
        // Fill stays the grey waste background; only a dashed outline +
        // dimension labels mark the rectangle. Same rotate mapping and manual
        // text centring as pieces above.
        foreach (($sheet['remnants'] ?? []) as $ri => $rem) {
            if ($rotate) {
                $px        = round($rem['y']      * $scale, 3);
                $py        = round($rem['x']      * $scale, 3);
                $pw        = round($rem['height'] * $scale, 3);
                $ph        = round($rem['width']  * $scale, 3);
                $labelHorz = $this->fmtNum($rem['height']);
                $labelVert = $this->fmtNum($rem['width']);
            } else {
                $px        = round($rem['x']      * $scale, 3);
                $py        = round($rem['y']      * $scale, 3);
                $pw        = round($rem['width']  * $scale, 3);
                $ph        = round($rem['height'] * $scale, 3);
                $labelHorz = $this->fmtNum($rem['width']);
                $labelVert = $this->fmtNum($rem['height']);
            }

            $clipId = 'rclip_' . $pieceOffset . '_' . $ri;
            $svg .= sprintf(
                '<defs><clipPath id="%s">'
                . '<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f"/>'
                . '</clipPath></defs>',
                $clipId, $px, $py, $pw, $ph
            );

            $svg .= sprintf(
                '<rect x="%.3f" y="%.3f" width="%.3f" height="%.3f" '
                . 'fill="none" stroke="%s" stroke-width="0.35" stroke-dasharray="2,1.2"/>',
                $px, $py, $pw, $ph,
                self::COL_REMNANT
            );

            $svg .= sprintf('<g clip-path="url(#%s)">', $clipId);

            $minSide   = min($pw, $ph);
            $threshold = 500 * $scale;
            $fontScale = $minSide < $threshold
                ? max(0.4, $minSide / $threshold)
                : 1.0;
            $dimFont   = round($baseDimFont * $fontScale, 3);

            $labelHorzW = $this->textWidth($labelHorz, $dimFont);
            $labelVertW = $this->textWidth($labelVert, $dimFont);

            // Horizontal dimension – near top border, manually centred.
            $svg .= sprintf(
                '<text x="%.3f" y="%.3f" font-size="%.2f" fill="%s" '
                . 'text-anchor="start" '
                . 'font-family="DejaVu Sans,sans-serif">%s</text>',
                $px + $pw / 2 - $labelHorzW / 2,
                $py + $dimFont,
                $dimFont,
                self::COL_REMNANT,
                $this->e($labelHorz)
            );

            // Vertical dimension – rotated -90°, manually centred along height.
            $cx = $px + $dimFont * 0.85;
            $cy = $py + $ph / 2 + $labelVertW / 2;
            $svg .= sprintf(
                '<text x="%.3f" y="%.3f" font-size="%.2f" fill="%s" '
                . 'text-anchor="start" font-family="DejaVu Sans,sans-serif" '
                . 'transform="rotate(-90,%.3f,%.3f)">%s</text>',
                $cx, $cy,
                $dimFont,
                self::COL_REMNANT,
                $cx, $cy,
                $this->e($labelVert)
            );

            $svg .= '</g>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /** HTML-escape a string. */
    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Formats a numeric dimension: integer if whole, otherwise keeps decimals.
     * Returns string for use in display.
     */
    private function fmtNum(float $v): string
    {
        return $v == (int) $v ? (string)(int)$v : (string)$v;
    }

    /**
     * Estimates the rendered width of a text string in mm.
     *
     * Uses per-character width ratios for DejaVu Sans to approximate
     * the actual rendered width without needing a font metrics library.
     * Digits and most Latin glyphs are ~0.55 em; brackets ~0.35 em;
     * narrow chars (i, l, 1, space) ~0.30 em; wide chars (m, w) ~0.75 em.
     *
     * @param string $text     The string to measure.
     * @param float  $fontSize Font size in mm.
     * @return float Estimated width in mm.
     */
    private function textWidth(string $text, float $fontSize): float
    {
        $width = 0.0;
        $len   = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch     = mb_substr($text, $i, 1, 'UTF-8');
            $width += match (true) {
                in_array($ch, ['[', ']', '(', ')', ' '], true)         => 0.35,
                in_array($ch, ['i', 'l', '1', '|', '.', ','], true)    => 0.32,
                in_array($ch, ['m', 'w', 'M', 'W'], true)              => 0.72,
                ctype_digit($ch)                                         => 0.55,
                ctype_upper($ch)                                         => 0.60,
                default                                                  => 0.52,
            };
        }
        return round($width * $fontSize, 3);
    }

    /** Clamps a float between min and max. */
    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
