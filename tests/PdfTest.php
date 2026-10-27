<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use Multiplast\SheetNester\SheetNester;
use Multiplast\SheetNester\SheetNesterPdf;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the PDF report. They need mpdf/mpdf (a dev dependency) and
 * are skipped when it is not installed.
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
        $pdf = new SheetNesterPdf(self::sampleResult(), [
            'title'        => 'Nářezový plán č. 1 <test>',
            'subtitle'     => 'Zákazník: Test & spol.',
            'material'     => 'PC 10 mm',
            'company_name' => 'ACME s.r.o.',
        ]);

        $binary = $pdf->generate();

        self::assertStringStartsWith('%PDF-', $binary);
        self::assertGreaterThan(5_000, strlen($binary));
    }

    public function testSaveWritesTheFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sheetnester-');
        try {
            (new SheetNesterPdf(self::sampleResult()))->save($path);

            self::assertFileExists($path);
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private static function sampleResult(): array
    {
        $nester = new SheetNester(kerf: 4, grainDirection: 'vertical', remnantMode: SheetNester::REMNANT_AUTO);
        $nester->addStockFormat(6000, 2100, 2);
        $nester->addStockFormat(3000, 2100, 2);
        $nester->addPiece(2200, 900, 5);
        $nester->addPiece(900, 810, 4);
        $nester->addPiece(7000, 100, 1);   // unplaced – exercises the warning table

        return $nester->calculate();
    }
}
