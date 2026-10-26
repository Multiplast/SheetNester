<?php

declare(strict_types=1);

/**
 * Ukázkové použití – PDF nářezový plán (vyžaduje mpdf/mpdf, v require-dev).
 * Spuštění: composer install && php examples/pdf.php
 */

use Multiplast\SheetNester\SheetNester;
use Multiplast\SheetNester\SheetNesterPdf;

require __DIR__ . '/../vendor/autoload.php';

$nester = new SheetNester(kerf: 4, grainDirection: 'vertical', formatStrategy: SheetNester::STRATEGY_MIN_WASTE, seed: 42);
$nester->addStockFormat(2100, 6000, 10);
$nester->addStockFormat(2100, 4000, 5);
$nester->addStockFormat(1500, 3000, 20);
$nester->addPiece(500,  1000, 3);
$nester->addPiece(300,  800,  5);
$nester->addPiece(1000, 2000, 2);

$result = $nester->calculate();

$pdf = new SheetNesterPdf($result, [
    'title'         => 'Nářezový plán č.22530',
    'subtitle'      => 'Zákazník: Jan Novák',
    'material'      => 'PC deska 2W 10 mm čirá',
    'company_name'  => 'TITAN – MULTIPLAST s.r.o.',
    'company_addr'  => 'Jablonecká 1379, 468 51 Smržovka',
    'company_phone' => '+420 483 360 060',
    'company_email' => 'info@titan-multiplast.cz',
    'company_www'   => 'www.titan-multiplast.cz',
    'company_ico'   => '25022229',
    'company_dic'   => 'CZ25022229',
]);

// Uložit na disk:
$pdf->save(__DIR__ . '/narezovy-plan.pdf');
echo "Uloženo: examples/narezovy-plan.pdf" . PHP_EOL;

// Nebo stáhnout do prohlížeče:   $pdf->download('narezovy-plan.pdf');
// Nebo binární řetězec (Nette):  $pdfString = $pdf->generate();
