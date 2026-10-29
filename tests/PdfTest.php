<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Render\PdfReport;
use Multiplast\SheetNester\Result\NestingResult;
use Multiplast\SheetNester\SheetNesterPdf;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the PDF rendering. They need mpdf/mpdf (a dev dependency)
 * and are skipped when it is not installed.
 */
final class PdfTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            self::markTestSkipped('mpdf/mpdf is not installed');
        }
    }

    public function testGenerateReturnsAPdfDocument(): void
    {
        $pdf = new PdfReport(self::sampleResult(), [
            'title'        => 'Cutting plan no. 1 <test>',
            'subtitle'     => 'Customer: Test & Co.',
            'material'     => 'PC 10 mm',
            'company_name' => 'ACME Ltd.',
        ]);

        $binary = $pdf->generate();

        self::assertStringStartsWith('%PDF-', $binary);
        self::assertGreaterThan(5_000, strlen($binary));
    }

    public function testSaveWritesTheFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sheetnester-');
        try {
            (new PdfReport(self::sampleResult(), [], PdfReport::LABELS_CS))->save($path);

            self::assertFileExists($path);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testLegacyWrapperStillGenerates(): void
    {
        $binary = (new SheetNesterPdf(self::sampleResult()->toLegacyArray(), ['title' => 'Plán']))->generate();

        self::assertStringStartsWith('%PDF-', $binary);
    }

    public function testOneSheetPerPagePrintsEachDiagramOnALandscapePage(): void
    {
        $result = self::sampleResult();
        $binary = (new PdfReport($result, [], [], oneSheetPerPage: true))->generate();

        // Summary stays portrait; one A4 landscape page (842 × 595 pt) per sheet.
        self::assertSame(count($result->sheets), substr_count($binary, '/MediaBox [0 0 841.890 595.280]'));
    }

    private static function sampleResult(): NestingResult
    {
        $job = NestingJob::create(
            new Material(kerf: 4, grain: Grain::ALONG_HEIGHT),
            search:   Search::singlePass(),
            remnants: Remnants::auto(200),
        )
            ->addStock(6000, 2100, 2)
            ->addStock(3000, 2100, 2)
            ->addPiece(2200, 900, 5, 'A')
            ->addPiece(900, 810, 4)
            ->addPiece(7000, 100, 1);   // unplaced – exercises the warning table

        return (new \Multiplast\SheetNester\SheetNester())->solve($job);
    }
}
