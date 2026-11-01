# SheetNester

2D nesting of rectangular pieces onto stock sheets – guillotine cuts (panel
saw), Best Area Fit, lookahead format selection, sheet elimination and format
reduction, usable-remnant marking. Built for plastic sheet materials
(polycarbonate, acrylic …), but any rectangular stock works. Pure PHP, no
dependencies; SVG previews and an optional PDF cutting plan (mPDF).

```php
use Multiplast\SheetNester\{Grain, NestingJob, SheetNester};
use Multiplast\SheetNester\Options\{Material, Objective, Remnants, Search, Upsize};

$job = NestingJob::create(
        material:  new Material(kerf: 4, grain: Grain::ALONG_HEIGHT),
        objective: Objective::minWaste(),
        search:    new Search(restarts: 50, seed: 42, timeBudgetMs: 8000),
        remnants:  Remnants::auto(minSide: 200),                         // mark leftovers (report only)
        upsize:    Upsize::lastSheet(minWidth: 2100, minHeight: 2500),   // optional: larger last sheet for a remnant
    )
    ->addStock(2100, 6000, quantity: 10)          // width, height [mm], sheets in stock
    ->addPiece(500, 1000, quantity: 3, label: 'A');

$result = (new SheetNester())->solve($job);       // Result\NestingResult

count($result->sheets);                            // sheets used
$result->materialM2;  $result->utilizationPct;     // totals
$result->sheets[0]->pieces[0];                     // Placement: x, y, width, height, rotated, label
$result->unplaced;                                 // UnplacedPieces: width, height, qty, reasonCode, label
```

Requirements: PHP ≥ 8.0 (deterministic results rely on the stable `usort`
PHP guarantees since 8.0). PDF output needs `mpdf/mpdf` (^8.1).

## Installation

```sh
composer require multiplast/sheet-nester
composer require mpdf/mpdf          # only for Render\PdfReport
```

Runnable examples: `composer install && php examples/basic.php` and
`php examples/pdf.php`.

## Coordinates and units

All lengths are millimetres. `width` is the X and `height` the Y dimension of
both stock sheets and pieces; placements are top-left corners in that system.
Enter sheets and pieces consistently – e.g. multiwall polycarbonate as
`2100 × 6000` with the channels along the height.

## Options

### `Material(kerf, grain)`

| Argument | Default | Meaning |
|---|---|---|
| `kerf` | `0.0` | Blade kerf in mm, added to the right and bottom of every piece footprint. |
| `grain` | `Grain::NONE` | Direction of the material's channels / grain: `NONE` (pieces may be rotated by 90°, nothing to seal), `ALONG_WIDTH` (channels along X), `ALONG_HEIGHT` (channels along Y). With channels pieces are never rotated and the seal length counts the two piece edges perpendicular to the channels. Placement does not depend on the axis – `ALONG_WIDTH` and `ALONG_HEIGHT` give the same plan, only the seal length differs. |

### `Objective` – what the solver minimises

Fewer unplaced pieces always wins first; the objective decides among plans
that place the same number of pieces.

| Factory | Minimises |
|---|---|
| `Objective::minWaste()` (default) | material (m²) |
| `Objective::minCuts(cutCostM2: 0.05)` | `material_m2 + cutCostM2 × saw passes`; ties → shorter cut length. `cutCostM2` is the material-equivalent value of one saw pass in m². Material changes in whole sheets, so values well below one sheet area rarely change the plan – they then only break ties. |
| `Objective::bestRemnant()` | material; ties → the plan whose largest single empty rectangle is biggest (one big offcut back to stock). Independent of the `Remnants` option: the rectangle is measured without its minimum size or formats. |

None of the objectives trades material for a remnant – that is what
`Upsize` is for (below).

### `Search(restarts, seed, timeBudgetMs, portfolio)` – how hard to try

The canonical First Fit Decreasing pass always runs. With `portfolio` (default
`true`) a handful of deterministic orderings follow (max side / width / height
/ perimeter descending), then `restarts` (default 20) seeded random shuffles;
the best plan wins. `Search::singlePass()` runs the FFD pass only – a few
milliseconds, good for live estimates. `timeBudgetMs` is a soft limit: the FFD
pass always runs, further orderings are skipped once the budget is spent and
the best so far is kept (`$result->timeBudgetHit`). With a `seed` and without
a time budget the same input always gives the same result.

Two separate options deal with remnants, and they combine freely:
`Remnants` only marks leftovers on the finished plan, `Upsize` may change the
consumption to create one.

### `Remnants` – marking usable leftovers (report only)

`Remnants::none()` (default). `Remnants::atLeast(width: 500, height: 1000)`
marks the largest empty rectangles that are at least `width × height` mm, in
the job's orientation (width along X): with channels a remnant cannot be
turned, so the minimum is orientation-bound, with `Grain::NONE` either
orientation qualifies. `Remnants::auto(minSide: 200)` is the square case
(shorter side ≥ `minSide`). `Remnants::defined([[1000, 500], …])` fits the
given formats (largest first, each repeatedly while it fits). Marking is
advisory: a marked remnant is not guaranteed to be separable with one
sequence of guillotine cuts.

Marking never influences the plan – it only decides which free rectangles
the result reports (`remnants`, `remnant_m2`, `remnant_pct`, `net_material_m2`
= material less marked remnants) and the renderers draw.

### `Upsize` – a larger sheet for a usable remnant (changes consumption)

`Upsize::none()` (default), `Upsize::lastSheet(…)`, `Upsize::allSheets(…)`.
After the solver has found the plan with the least material, a sheet may be
moved to a larger stock format when that turns its waste into a usable
remnant. `lastSheet()` considers only the last sheet of the plan – the sheet
opened last, which holds the leftover pieces (with a single sheet, that one).
`allSheets()` considers every sheet; with many stock formats that also
enlarges sheets for a few millimetres of waste, so start with `lastSheet()`.
The pieces keep their positions; only the format of a sheet changes, and
`SheetPlan::$upsizedFrom` (`upsized_from`) records the original one.

The threshold – what counts as a usable remnant – belongs to this option and
is independent of `Remnants`: a minimum size (`minWidth`, `minHeight`, same
orientation rules as `Remnants::atLeast()`) or a list of `formats` one of which
must fit:

```php
Upsize::lastSheet(minWidth: 2500, minHeight: 1000)
Upsize::lastSheet(formats: [[2500, 2100], [3000, 2100]])
```

The candidates are the available formats that still hold the sheet's pieces;
each is valued by its net material, `format area − area of the remnants that
meet the threshold`. The sheet moves only on a strict improvement, ties go to
the smaller format, so a full sheet never grows and a sheet grows only when
its waste becomes part of a remnant that meets the threshold. Example – one
3000 × 2100 piece, formats 6000 / 5000 / 4000 × 2100,
`Upsize::lastSheet(minWidth: 2500, minHeight: 1000)`:

| Format | Leftover | Meets 2500 × 1000 | Net material |
|---|---|---|---|
| 4000 × 2100 | 1000 × 2100 | no | 8.40 m² |
| 5000 × 2100 | 2000 × 2100 | no | 10.50 m² |
| 6000 × 2100 | 3000 × 2100 | yes | **6.30 m²** ← chosen |

The step runs on the winning plan only (also with `Search::singlePass()`), it
never moves pieces between sheets, with `lastSheet()` it leaves the other
sheets alone even when they have some waste, and with `Objective::minCuts()`
the cut that separates the new remnant is counted in the result but not in
the comparison of the candidate plans. Choose the threshold as the smallest
remnant you would sell or stock as a full sheet: a low threshold makes almost
every leftover count and the step then changes nothing (every format ties).

How the two combine:

| `remnants` | `upsize` | Result |
|---|---|---|
| `none()` | `none()` | plan with the least material, no remnants reported |
| `auto(200)` | `none()` | same plan, leftovers from 200 mm marked |
| `none()` | `lastSheet(2500, 1000)` | the last sheet may grow; remnants not marked, the PDF still notes the enlargement |
| `auto(200)` | `lastSheet(2500, 1000)` | grows only for a 2500 × 1000 remnant; every leftover from 200 mm marked |

The 2.1 form `Remnants::atLeast(…, upsize: Remnants::UPSIZE_LAST)` still works
(deprecated): it becomes the matching `Upsize` option with the marking
threshold. It cannot be combined with an explicit `upsize:`.

### Job

`NestingJob::create(material, objective, search, remnants, upsize)` – every
argument optional. `->addStock(width, height, quantity)` (omit `quantity` for
unlimited stock), `->addPiece(width, height, quantity, label)`.

## Result

`SheetNester::solve()` returns a `Result\NestingResult` (immutable by
convention):

| | |
|---|---|
| `sheets` | `SheetPlan[]`, largest format first: `index`, `width`, `height`, `usedPct` (incl. kerf), `pieces` (`Placement`: `x`, `y`, `width`, `height`, `rotated`, `label`), `cuts` (`CutPlan`: `horizontal`/`vertical` `Cut` lists, lengths, `count()`), `sealMm`, `remnants` (`Remnant[]` or `null`), `remnantM2` |
| `stockUsage` | `StockUsage[]`: `width`, `height`, `used`, `remaining` (`null` = unlimited) |
| `unplaced` | `UnplacedPieces[]`: `width`, `height`, `qty`, `reasonCode` (`stock`, `too_large`), `reason()`, `label`; `unplacedCount()` |
| totals | `materialM2`, `utilizationPct` (piece area / material), `wastePctAvg`, `cutCount`, `cutsMm`, `cutsHorizontalMm`, `cutsVerticalMm`, `sealMm` (`null` without channels), `remnantM2`, `remnantPct` |
| run | `objective`, `passes`, `timeBudgetHit`, `calculationMs` |

`toArray()` gives a JSON-friendly array, `fromArray()` rebuilds the object
(e.g. from a database). `toLegacyArray()` / `fromLegacyArray()` use the 1.x
array shape – see [UPGRADE.md](UPGRADE.md).

Saw cuts are the merged collinear piece edges inside the sheet (sheet borders
are not cuts); `cutCount` approximates the number of saw passes.

## Rendering

```php
use Multiplast\SheetNester\Render\{PdfReport, SvgRenderer};

$svg = (new SvgRenderer())->render($result->sheets[0]);          // well-formed SVG
$pdf = new PdfReport($result, ['title' => 'Cutting plan 123', 'material' => 'PC 10 mm']);
$pdf->save('/path/plan.pdf');   // or generate() / download() / inline() / html()
```

Both renderers take a label map as an extra argument (`SvgRenderer::LABELS_CS`,
`PdfReport::LABELS_CS`, or your own with the same keys); the defaults are
English. `PdfReport::html()` works without mPDF.

`PdfReport` draws one diagram per sheet. With `groupIdenticalSheets: true`,
sheets with the same format and layout share one diagram whose header gives
the number of sheets and the totals over them. The diagrams are then numbered
as plans, the pieces and remnants tables refer to those plan numbers, and
`[N]` in a diagram is the row of the piece in the pieces table:

```php
$pdf = new PdfReport($result, $meta, PdfReport::LABELS_CS, groupIdenticalSheets: true);
```

By default two diagrams share a portrait A4 page. With `oneSheetPerPage: true`
every diagram gets its own A4 landscape page and the summary stays portrait.
Drawings and labels are about 1.5× larger, at the cost of roughly twice the
pages, so it pairs well with `groupIdenticalSheets`.

## Tests

```sh
composer install
composer test            # = vendor/bin/phpunit
```

Golden tests pin the complete output and SVG previews of a reference job
(30 formats, 82 pieces: 18 sheets / 101.92 m² with `minWaste`, 200 restarts,
seed 42) in `tests/fixtures`; a change that is not meant to change behaviour
must leave them untouched. Property-based tests check geometry, kerf gaps,
piece conservation, stock limits, totals, cuts, seal and remnants on random
jobs, and that multi-pass searches never do worse than the single FFD pass.
CI runs the suite on PHP 8.0–8.5.

To accept an intended behaviour change, regenerate the fixtures and review the
diff: `UPDATE_GOLDEN=1 vendor/bin/phpunit --filter GoldenTest`.

mPDF needs the `gd` extension; if your CLI lacks it (XAMPP), use
`composer install --ignore-platform-req=ext-gd` and
`php -d extension=gd vendor/bin/phpunit`.

## Versioning

[SemVer](https://semver.org/); releases are git tags (`git tag v2.0.0 && git push origin v2.0.0`).
The public API is `SheetNester`, `NestingJob`, `Grain`, the `Options\*`,
`Result\*` and `Render\*` classes and the two array shapes. Everything in
`Internal\` is `@internal` and may change in any release.

## Licence

See [LICENSE](LICENSE).
