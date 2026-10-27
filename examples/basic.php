<?php

declare(strict_types=1);

/**
 * Basic usage – solve a job, print the summary, save SVG previews.
 * Run: composer install && php examples/basic.php
 */

use Multiplast\SheetNester\Grain;
use Multiplast\SheetNester\NestingJob;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;
use Multiplast\SheetNester\Render\SvgRenderer;
use Multiplast\SheetNester\SheetNester;

require __DIR__ . '/../vendor/autoload.php';

// Multiwall polycarbonate: the channels run along the sheet length, which is
// entered as the height (Y) here – so pieces are never rotated and the seal
// length counts the top and bottom edge of every piece.
$job = NestingJob::create(
        material:  new Material(kerf: 4, grain: Grain::ALONG_HEIGHT),
        objective: Objective::minWaste(),
        search:    new Search(restarts: 50, seed: 42, timeBudgetMs: 8000),
        remnants:  Remnants::auto(minSide: 200),
    )
    ->addStock(2100, 6000, quantity: 10)      // width, height [mm], sheets in stock (omit = unlimited)
    ->addStock(2100, 4000, quantity: 5)
    ->addStock(1500, 3000, quantity: 20)
    ->addPiece(500,  1000, quantity: 3, label: 'A')   // width, height [mm], pieces, optional label
    ->addPiece(300,  800,  quantity: 5, label: 'B')
    ->addPiece(1000, 2000, quantity: 2, label: 'C');

$result = (new SheetNester())->solve($job);

printf("Objective:        %s (%d orderings tried, %.1f ms)\n", $result->objective, $result->passes, $result->calculationMs);
printf("Sheets:           %d\n", count($result->sheets));
printf("Material:         %.2f m², utilization %.2f %%\n", $result->materialM2, $result->utilizationPct);
printf("Saw cuts:         %d (%.1f m)\n", $result->cutCount, $result->cutsMm / 1000);
printf("Seal length:      %.1f m\n", ($result->sealMm ?? 0) / 1000);
printf("Usable remnants:  %.2f m² (%.1f %%)\n", $result->remnantM2 ?? 0, $result->remnantPct ?? 0);

foreach ($result->unplaced as $u) {
    printf("Unplaced: %g×%g × %d (%s) – %s\n", $u->width, $u->height, $u->qty, $u->label ?? '-', $u->reason());
}

foreach ($result->sheets as $sheet) {
    printf("  sheet #%d  %g×%g  pieces: %d  used: %.1f %%\n",
        $sheet->index + 1, $sheet->width, $sheet->height, count($sheet->pieces), $sheet->usedPct);
}

// SVG previews (valid standalone XML); pass SvgRenderer::LABELS_CS for Czech texts
$svg = new SvgRenderer();
foreach ($result->sheets as $sheet) {
    file_put_contents(__DIR__ . "/sheet_{$sheet->index}.svg", $svg->render($sheet));
}

// JSON for storage – NestingResult::fromArray() rebuilds the object later
file_put_contents(__DIR__ . '/result.json', json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// A quick estimate: the single FFD pass takes a few milliseconds
$quick = (new SheetNester())->solve(
    NestingJob::create(new Material(kerf: 4, grain: Grain::ALONG_HEIGHT), search: Search::singlePass())
        ->addStock(2100, 6000)
        ->addPiece(500, 1000, 3)
        ->addPiece(300, 800, 5)
        ->addPiece(1000, 2000, 2)
);
printf("\nSingle pass: %d sheet(s), %.2f m², %.1f ms\n", count($quick->sheets), $quick->materialM2, $quick->calculationMs);
