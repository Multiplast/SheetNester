<?php

declare(strict_types=1);

/**
 * Ukázkové použití – výpočet a SVG náhled.
 * Spuštění: composer install && php examples/basic.php
 */

use Multiplast\SheetNester\SheetNester;

require __DIR__ . '/../vendor/autoload.php';

// --- Strategie smallest_fit (výchozí) ---
$nester = new SheetNester(kerf: 4, grainDirection: 'vertical', formatStrategy: SheetNester::STRATEGY_SMALLEST_FIT);

$nester->addStockFormat(2100, 6000, 10);
$nester->addStockFormat(2100, 4000, 5);
$nester->addStockFormat(1500, 3000, 20);

$nester->addPiece(500,  1000, 3);
$nester->addPiece(300,  800,  5);
$nester->addPiece(1000, 2000, 2);

$result = $nester->calculate();
echo "Strategie:            " . $result['strategy'] . PHP_EOL;
echo "Použito desek:        " . $result['total_sheets'] . PHP_EOL;
echo "Využití materiálu:    " . $result['utilization_pct'] . " %" . PHP_EOL;
echo "Čas výpočtu:          " . $result['calculation_time_ms'] . " ms" . PHP_EOL;
echo "Testovaných průchodů: " . $result['permutations_tested'] . PHP_EOL . PHP_EOL;


// --- Strategie min_waste (více průchodů, seed = reprodukovatelný výsledek) ---
$nester2 = new SheetNester(
    kerf: 4,
    grainDirection: 'vertical',
    formatStrategy: SheetNester::STRATEGY_MIN_WASTE,
    restarts: 50,
    seed: 42,
    timeBudgetMs: 8000,
    remnantMode: SheetNester::REMNANT_AUTO,
    minRemnantSide: 200,
);

$nester2->addStockFormat(2100, 6000, 10);
$nester2->addStockFormat(2100, 4000, 5);
$nester2->addStockFormat(1500, 3000, 20);

$nester2->addPiece(500,  1000, 3);
$nester2->addPiece(300,  800,  5);
$nester2->addPiece(1000, 2000, 2);

$result2 = $nester2->calculate();
echo "Strategie:            " . $result2['strategy'] . PHP_EOL;
echo "Testovaných průchodů: " . $result2['permutations_tested'] . PHP_EOL;
echo "Použito desek:        " . $result2['total_sheets'] . PHP_EOL;
echo "Materiál:             " . $result2['total_material_m2'] . " m²" . PHP_EOL;
echo "Využití materiálu:    " . $result2['utilization_pct'] . " %" . PHP_EOL;
echo "Použitelné zbytky:    " . $result2['total_remnant_m2'] . " m²" . PHP_EOL;
echo "Čas výpočtu:          " . $result2['calculation_time_ms'] . " ms" . PHP_EOL;

foreach ($result2['unplaced_pieces'] as $p) {
    echo "Nelze umístit: {$p['width']}×{$p['height']} × {$p['qty']} ks – {$p['reason']}" . PHP_EOL;
}

for ($i = 0; $i < $result2['total_sheets']; $i++) {
    file_put_contents(__DIR__ . "/sheet_{$i}.svg", $nester2->toSvg($i));
}
