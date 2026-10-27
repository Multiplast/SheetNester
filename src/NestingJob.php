<?php

declare(strict_types=1);

namespace Multiplast\SheetNester;

use Multiplast\SheetNester\Internal\Piece;
use Multiplast\SheetNester\Internal\StockFormat;
use Multiplast\SheetNester\Options\Material;
use Multiplast\SheetNester\Options\Objective;
use Multiplast\SheetNester\Options\Remnants;
use Multiplast\SheetNester\Options\Search;

/**
 * Everything one nesting run needs: the options and the lists of stock
 * formats and pieces. Build it fluently, then pass it to SheetNester::solve().
 *
 *   $job = NestingJob::create(
 *           material:  new Material(kerf: 4, grain: Grain::ALONG_HEIGHT),
 *           objective: Objective::minCuts(cutCostM2: 0.05),
 *           search:    new Search(restarts: 50, seed: 42, timeBudgetMs: 8000),
 *           remnants:  Remnants::auto(minSide: 200),
 *       )
 *       ->addStock(2100, 6000, quantity: 10)
 *       ->addPiece(500, 1000, quantity: 3, label: 'window A');
 *
 * All lengths are millimetres; width is the X and height the Y dimension of
 * both sheets and pieces.
 */
final class NestingJob
{
    /** @var StockFormat[] */
    private array $stock = [];

    /** @var Piece[] */
    private array $pieces = [];

    public function __construct(
        private Material  $material,
        private Objective $objective,
        private Search    $search,
        private Remnants  $remnants,
    ) {
    }

    /** Defaults: solid material without kerf, least material, 20 restarts, no remnants. */
    public static function create(
        ?Material  $material = null,
        ?Objective $objective = null,
        ?Search    $search = null,
        ?Remnants  $remnants = null
    ): self {
        return new self(
            $material  ?? new Material(),
            $objective ?? Objective::minWaste(),
            $search    ?? new Search(),
            $remnants  ?? Remnants::none()
        );
    }

    /**
     * Registers an available stock sheet format.
     *
     * @param float $width    Sheet width in mm.
     * @param float $height   Sheet height in mm.
     * @param int   $quantity Number of sheets available.
     */
    public function addStock(float $width, float $height, int $quantity): self
    {
        if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Stock format dimensions must be positive numbers.');
        }
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Stock quantity must be at least 1.');
        }
        $this->stock[] = new StockFormat($width, $height, $quantity);
        return $this;
    }

    /**
     * Adds a piece (or several identical pieces) to the job.
     *
     * @param float       $width    Piece width in mm.
     * @param float       $height   Piece height in mm.
     * @param int         $quantity Number of identical pieces.
     * @param string|null $label    Free text carried to every placement of the piece.
     */
    public function addPiece(float $width, float $height, int $quantity = 1, ?string $label = null): self
    {
        if (!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Piece dimensions must be positive numbers.');
        }
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Piece quantity must be at least 1.');
        }
        $this->pieces[] = new Piece($width, $height, $quantity, $label);
        return $this;
    }

    public function material(): Material
    {
        return $this->material;
    }

    public function objective(): Objective
    {
        return $this->objective;
    }

    public function search(): Search
    {
        return $this->search;
    }

    public function remnants(): Remnants
    {
        return $this->remnants;
    }

    /**
     * @internal Engine input.
     * @return StockFormat[]
     */
    public function stock(): array
    {
        return $this->stock;
    }

    /**
     * @internal Engine input.
     * @return Piece[]
     */
    public function pieces(): array
    {
        return $this->pieces;
    }
}
