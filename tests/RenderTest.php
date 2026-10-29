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
        self::assertSame(count($result->sheets), substr_count($html, '<div class="sheet-block">'));

        $czech = (new PdfReport($result, [], PdfReport::LABELS_CS))->html();
        self::assertStringContainsString('Nářezový plán', $czech);
        self::assertStringContainsString('Zaslepení (páska)', $czech);
        self::assertStringContainsString('Spotřeba materiálu', $czech);
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

    public function testArrayRoundTrips(): void
    {
        $result = self::sampleResult();

        $array = $result->toArray();
        self::assertSame($array, NestingResult::fromArray($array)->toArray());
        self::assertSame($array, NestingResult::fromArray(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true))->toArray());

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
