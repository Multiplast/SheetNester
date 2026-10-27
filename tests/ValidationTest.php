<?php

declare(strict_types=1);

namespace Multiplast\SheetNester\Tests;

use InvalidArgumentException;
use Multiplast\SheetNester\SheetNester;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testConstructorRejectsInvalidArguments(): void
    {
        $invalid = [
            'grainDirection'      => fn() => new SheetNester(kerf: 0, grainDirection: 'diagonal'),
            'formatStrategy'      => fn() => new SheetNester(kerf: 0, formatStrategy: 'fastest'),
            'restarts < 0'        => fn() => new SheetNester(kerf: 0, restarts: -1),
            'timeBudgetMs = 0'    => fn() => new SheetNester(kerf: 0, timeBudgetMs: 0),
            'timeBudgetMs < 0'    => fn() => new SheetNester(kerf: 0, timeBudgetMs: -100),
            'remnantMode'         => fn() => new SheetNester(kerf: 0, remnantMode: 'all'),
            'minRemnantSide = 0'  => fn() => new SheetNester(kerf: 0, minRemnantSide: 0),
            'cutCostM2 < 0'       => fn() => new SheetNester(kerf: 0, cutCostM2: -0.01),
        ];

        foreach ($invalid as $label => $construct) {
            try {
                $construct();
                self::fail("Constructor accepted invalid {$label}");
            } catch (InvalidArgumentException $e) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testConstructorAcceptsEveryDocumentedValue(): void
    {
        foreach (['horizontal', 'vertical', 'none'] as $grain) {
            foreach ([
                SheetNester::STRATEGY_SMALLEST_FIT,
                SheetNester::STRATEGY_MIN_WASTE,
                SheetNester::STRATEGY_MIN_CUTS,
                SheetNester::STRATEGY_BEST_REMNANT,
            ] as $strategy) {
                foreach ([SheetNester::REMNANT_NONE, SheetNester::REMNANT_AUTO, SheetNester::REMNANT_DEFINED] as $remnant) {
                    new SheetNester(
                        kerf: 0,
                        grainDirection: $grain,
                        formatStrategy: $strategy,
                        restarts: 0,
                        seed: null,
                        timeBudgetMs: null,
                        remnantMode: $remnant,
                        minRemnantSide: 1,
                        cutCostM2: 0,
                    );
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    public function testInputMethodsRejectNonPositiveValues(): void
    {
        $nester  = new SheetNester(kerf: 0);
        $invalid = [
            'stock width 0'    => fn() => $nester->addStockFormat(0, 100, 1),
            'stock height < 0' => fn() => $nester->addStockFormat(100, -1, 1),
            'stock count 0'    => fn() => $nester->addStockFormat(100, 100, 0),
            'piece width 0'    => fn() => $nester->addPiece(0, 100, 1),
            'piece height < 0' => fn() => $nester->addPiece(100, -5, 1),
            'piece qty 0'      => fn() => $nester->addPiece(100, 100, 0),
            'remnant width 0'  => fn() => $nester->addRemnantFormat(0, 100),
            'remnant height 0' => fn() => $nester->addRemnantFormat(100, 0),
        ];

        foreach ($invalid as $label => $call) {
            try {
                $call();
                self::fail("Accepted invalid {$label}");
            } catch (InvalidArgumentException $e) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSvgIndexOutOfRange(): void
    {
        $nester = new SheetNester(kerf: 0);
        $nester->addStockFormat(1000, 1000, 1);
        $nester->addPiece(500, 500, 1);
        $nester->calculate();

        $this->expectException(OutOfRangeException::class);
        $nester->toSvg(1);
    }
}
