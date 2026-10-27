<?php

declare(strict_types=1);

/**
 * PDF cutting plan (needs mpdf/mpdf, a dev dependency of this package).
 * Run: composer install && php examples/pdf.php
 */

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Render\PdfReport;
use Multiplast\SheetNester\SheetNester;

require __DIR__ . '/../vendor/autoload.php';

$job = NestingJob::create(
        material: new Material(kerf: 4, grain: Grain::ALONG_HEIGHT),
        search:   new Search(restarts: 50, seed: 42),
        remnants: Remnants::auto(minSide: 200),
    )
    ->addStock(2100, 6000, quantity: 10)
    ->addStock(2100, 4000, quantity: 5)
    ->addStock(1500, 3000, quantity: 20)
    ->addPiece(500,  1000, quantity: 3, label: 'A')
    ->addPiece(300,  800,  quantity: 5, label: 'B')
    ->addPiece(1000, 2000, quantity: 2, label: 'C');

$result = (new SheetNester())->solve($job);

$pdf = new PdfReport($result, [
    'title'          => 'Cutting plan no. 22530',
    'subtitle'       => 'Customer: John Doe',
    'material'       => 'PC multiwall 10 mm clear',
    'company_name'   => 'ACME Plastics Ltd.',
    'company_addr'   => '1 Example Street, 12345 Springfield',
    'company_phone'  => '+1 555 0100',
    'company_email'  => 'info@example.com',
    'company_www'    => 'www.example.com',
    'company_id'     => '12345678',
    'company_vat_id' => 'XX12345678',
], PdfReport::LABELS_EN);   // or PdfReport::LABELS_CS, or your own map

// Save to disk:
$pdf->save(__DIR__ . '/cutting-plan.pdf');
echo "Saved: examples/cutting-plan.pdf" . PHP_EOL;

// Or send to the browser:   $pdf->download('cutting-plan.pdf');
// Or get the bytes:         $bytes = $pdf->generate();
// Or just the HTML:         $html  = $pdf->html();
