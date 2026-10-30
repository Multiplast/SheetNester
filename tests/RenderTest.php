<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Render\PdfReport;
use Multiplast\SheetNester\Render\SvgRenderer;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\SheetNester;
use PHPUnit\Framework\TestCase;

/**
 * SVG preview, PDF HTML and the array round trips of NestingResult.
 */
final class RenderTest extends TestCase
{
    private static function sampleResult(): NestingResult
    {
        $job = NestingJob::create(
            new Material(kerf: 4, grain: Grain::ALONG_HEIGHT),
            search:   Search::singlePass(),
            remnants: Remnants::auto(200),
        )
            ->addStock(6000, 2100, 2)
            ->addStock(3000, 2100, 2)
            ->addPiece(2200, 900, 5, 'okno "A" & B')
            ->addPiece(900, 810, 4)
            ->addPiece(7000, 100, 1, 'too long');   // unplaced

        return (new SheetNester())->solve($job);
    }

    public function testSvgIsWellFormedXmlWithLabels(): void
    {
        $result = self::sampleResult();
        $svg    = (new SvgRenderer())->render($result->sheets[0]);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($svg);
        self::assertNotFalse($xml, 'SVG must be well-formed XML: ' . implode('; ', array_map(fn($e) => trim($e->message), libxml_get_errors())));
        libxml_clear_errors();

        self::assertStringContainsString('Sheet #1', $svg);
        self::assertStringContainsString('Utilization:', $svg);
        self::assertStringContainsString('&#160;', $svg);
        self::assertStringNotContainsString('&nbsp;', $svg);
        self::assertStringContainsString('okno &quot;A&quot; &amp; B', $svg);   // piece label, escaped
        self::assertStringContainsString('offcut ', $svg);

        $czech = (new SvgRenderer(SvgRenderer::LABELS_CS))->render($result->sheets[0]);
        self::assertStringContainsString('Deska #1', $czech);
        self::assertStringContainsString('zbytek ', $czech);

        $noLabels = (new SvgRenderer([], false))->render($result->sheets[0]);
        self::assertStringNotContainsString('okno', $noLabels);
    }

    public function testPdfHtmlContainsTheReportSections(): void
    {
        $result = self::sampleResult();
        $html   = (new PdfReport($result, [
            'title'          => 'Plan <1>',
            'material'       => 'PC 10 mm',
            'company_name'   => 'ACME & Co.',
            'company_id'     => '12345678',
            'company_vat_id' => 'CZ12345678',
        ]))->html();

        self::assertStringContainsString('Plan &lt;1&gt;', $html);
        self::assertStringContainsString('ACME &amp; Co.', $html);
        self::assertStringContainsString('Company ID: 12345678', $html);
        self::assertStringContainsString('Seal length', $html);
        self::assertStringContainsString(round($result->sealMm / 1000, 1) . ' m', $html);
        self::assertStringContainsString('Saw cuts', $html);
        self::assertStringContainsString('Material consumption:</td><td class="stat-val">' . $result->materialM2 . ' m²', $html);
        self::assertStringContainsString('Least material', $html);          // objective by name …
        self::assertStringNotContainsString('min_waste', $html);            // … not by identifier
        self::assertStringContainsString('Unplaced pieces', $html);
        self::assertStringContainsString('Larger than every stock format.', $html);
        self::assertStringContainsString('okno &quot;A&quot; &amp; B', $html);       // label column
        self::assertStringContainsString('Usable remnants', $html);
        self::assertStringContainsString('Consumption (less remnants):</td><td class="stat-val">' . $result->netMaterialM2 . ' m²', $html);
        self::assertStringNotContainsString('enlarged from', $html);
        self::assertSame(count($result->sheets), substr_count($html, '<div class="sheet-block">'));

        $czech = (new PdfReport($result, [], PdfReport::LABELS_CS))->html();
        self::assertStringContainsString('Nářezový plán', $czech);
        self::assertStringContainsString('Zaslepení (páska)', $czech);
        self::assertStringContainsString('Spotřeba materiálu', $czech);
        self::assertStringContainsString('Spotřeba (bez zbytků)', $czech);
        self::assertStringContainsString('Kus je větší než všechny dostupné formáty desek.', $czech);
        self::assertStringContainsString('Nejméně materiálu', $czech);
    }

    public function testLegacyPdfWrapperAcceptsTheLegacyArrayAndMeta(): void
    {
        $result = self::sampleResult();
        $pdf    = new \Multiplast\SheetNester\SheetNesterPdf($result->toLegacyArray(), [
            'title'        => 'Plán 7',
            'company_name' => 'TITAN – Multiplast s.r.o.',
            'company_ico'  => '25022229',
            'company_dic'  => 'CZ25022229',
        ]);

        // html() is on PdfReport only; reach it through reflection to avoid mPDF
        $prop = new \ReflectionProperty($pdf, 'report');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $html = $prop->getValue($pdf)->html();

        self::assertStringContainsString('Plán 7', $html);
        self::assertStringContainsString('IČO: 25022229', $html);
        self::assertStringContainsString('DIČ: CZ25022229', $html);
        self::assertStringContainsString('Kus je větší než všechny dostupné formáty desek.', $html);
        self::assertStringContainsString('Zaslepení (páska)', $html);
        self::assertStringContainsString('Nejméně materiálu', $html);
    }

    public function testReportsAndArraysCarryTheEnlargedSheet(): void
    {
        $job = NestingJob::create(new Material(0, Grain::ALONG_WIDTH), search: Search::singlePass(), remnants: Remnants::atLeast(2500, 1000, upsize: Remnants::UPSIZE_LAST))
            ->addStock(6000, 2100, 5)
            ->addStock(4000, 2100, 5)
            ->addPiece(3000, 2100);
        $result = (new SheetNester())->solve($job);
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $result->sheets[0]->upsizedFrom);

        $html = (new PdfReport($result))->html();
        self::assertStringContainsString('6000 × 2100 mm (enlarged from 4000 × 2100 mm for a usable remnant)', $html);
        self::assertStringContainsString('Consumption (less remnants):</td><td class="stat-val">6.3 m²', $html);

        $czech = (new PdfReport($result, [], PdfReport::LABELS_CS))->html();
        self::assertStringContainsString('zvětšeno z 4000 × 2100 mm kvůli použitelnému zbytku', $czech);
        self::assertStringContainsString('Spotřeba (bez zbytků):</td><td class="stat-val">6.3 m²', $czech);

        // the SVG preview is unchanged by the enlargement (it draws the final sheet)
        self::assertStringContainsString('offcut ', (new SvgRenderer())->render($result->sheets[0]));

        $array = $result->toArray();
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $array['sheets'][0]['upsized_from']);
        self::assertSame(6.3, $array['totals']['net_material_m2']);
        self::assertSame($array, NestingResult::fromArray($array)->toArray());
        $decoded = NestingResult::fromArray(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true));
        self::assertSame(['width' => 4000.0, 'height' => 2100.0], $decoded->sheets[0]->upsizedFrom);
        self::assertSame(6.3, $decoded->netMaterialM2);

        // the 1.x shape knows neither field
        $legacy = $result->toLegacyArray();
        self::assertArrayNotHasKey('upsized_from', $legacy['sheets'][0]);
        self::assertArrayNotHasKey('net_material_m2', $legacy);
        self::assertNull(NestingResult::fromLegacyArray($legacy)->sheets[0]->upsizedFrom);
    }

    public function testPdfCanGroupIdenticalSheets(): void
    {
        $job = NestingJob::create(new Material(kerf: 0), search: Search::singlePass())
            ->addStock(1000, 1000, 5)
            ->addPiece(1000, 500, 5, 'P');
        $result = (new SheetNester())->solve($job);
        self::assertCount(3, $result->sheets);                                  // 2 + 2 + 1 pieces

        $default = (new PdfReport($result))->html();
        self::assertSame(3, substr_count($default, '<div class="sheet-block">'));
        self::assertStringContainsString('[5]', $default);                      // running piece number
        self::assertStringContainsString('<th>Sheet</th>', $default);

        $grouped = (new PdfReport($result, [], [], groupIdenticalSheets: true))->html();
        self::assertSame(2, substr_count($grouped, '<div class="sheet-block">'));
        self::assertStringContainsString('<strong>2 pcs (2 m²)</strong>', $grouped);   // sheets 1 + 2 = plan 1
        self::assertStringContainsString('Pieces: 4 pcs (2 m²)', $grouped);           // totals over both
        self::assertStringContainsString('<td class="sh-num">2</td>', $grouped);       // sheet 3 = plan 2
        self::assertStringContainsString('<th>Plan</th>', $grouped);
        self::assertStringContainsString('<td>1, 2</td>', $grouped);                   // pieces table → plans
        self::assertStringContainsString('[1]', $grouped);                             // [N] = pieces table row
        self::assertStringNotContainsString('[2]', $grouped);

        $czech = (new PdfReport($result, [], PdfReport::LABELS_CS, groupIdenticalSheets: true))->html();
        self::assertStringContainsString('<th>Plán</th>', $czech);

        $pages = (new PdfReport($result, [], [], groupIdenticalSheets: true, oneSheetPerPage: true))->html();
        self::assertSame(2, substr_count($pages, '<pagebreak orientation="landscape" />'));   // one page per plan
    }

    public function testPiecesTableKeepsEveryRowOnOneLine(): void
    {
        // 5 sheets of one piece each + a piece on sheets 1, 2 and 4 → ranges and pairs
        $job = NestingJob::create(new Material(kerf: 0), search: Search::singlePass())
            ->addStock(1000, 1000)
            ->addPiece(1000, 1000, 5, 'Full')
            ->addPiece(1000, 1000, 1, 'Last');
        $html = (new PdfReport((new SheetNester())->solve($job)))->html();

        self::assertStringContainsString('<table class="plan-tbl pieces-tbl">', $html);
        self::assertStringContainsString('table.pieces-tbl th, table.pieces-tbl td { white-space: nowrap;', $html);
        self::assertStringContainsString('<td>1–5</td>', $html);                // runs of 3+ become a range
        self::assertStringContainsString('<td>6</td>', $html);
        self::assertStringNotContainsString('1, 2, 3', $html);
    }

    public function testPdfCanPutEachDiagramOnALandscapePage(): void
    {
        $result = self::sampleResult();
        $sheets = count($result->sheets);

        $default = (new PdfReport($result))->html();
        self::assertStringNotContainsString('<pagebreak', $default);
        self::assertStringContainsString('width="175mm"', $default);            // 6000 × 2100, portrait width

        $html = (new PdfReport($result, [], [], oneSheetPerPage: true))->html();
        self::assertSame($sheets, substr_count($html, '<pagebreak orientation="landscape" />'));
        self::assertSame($sheets, substr_count($html, '<div class="sheet-block">'));
        self::assertStringNotContainsString('class="sheet-sep"', $html);
        self::assertStringContainsString('width="267mm"', $html);               // landscape width
    }

    public function testArrayRoundTrips(): void
    {
        $result = self::sampleResult();

        $array = $result->toArray();
        self::assertSame($array, NestingResult::fromArray($array)->toArray());
        self::assertSame($array, NestingResult::fromArray(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true))->toArray());
        self::assertNull(NestingResult::fromArray($array)->sheets[0]->upsizedFrom);

        $legacy = $result->toLegacyArray();
        self::assertSame($legacy, NestingResult::fromLegacyArray($legacy)->toLegacyArray());

        $quick = $legacy;
        $quick['strategy'] = 'smallest_fit';                                 // as stored by 1.x
        self::assertSame('min_waste', NestingResult::fromLegacyArray($quick)->objective);

        foreach (['min_waste-r200', 'min_waste-r20-kerf4-none', 'min_waste-r20-unplaced'] as $fixture) {
            $stored = json_decode((string) file_get_contents(__DIR__ . "/fixtures/{$fixture}.json"), true, 512, JSON_THROW_ON_ERROR);
            $stored['calculation_time_ms'] = 0.0;   // normalize() strips it; fromLegacyArray needs a value
            self::assertSame($stored, NestingResult::fromLegacyArray($stored)->toLegacyArray(), $fixture);
        }
    }
}
