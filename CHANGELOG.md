# Changelog

## Unreleased

- `Render\PdfReport`: the pieces table keeps every row on one line (no
  wrapping; mPDF shrinks the table to the page width when a label is very
  long), with slightly narrower cell padding.
- `Render\PdfReport`: sheet and plan numbers in the pieces and remnants
  tables collapse runs of three or more into a range (`1–5, 23` instead of
  `1, 2, 3, 4, 5, 23`).
- `Render\PdfReport`: the net material row is labelled `Spotřeba (bez
  zbytků)` / `Consumption (less remnants)` (label key `stat_net_material`).

## 2.1.0 – 2026-10-29

### Added

- **`Remnants::…(upsize: Remnants::UPSIZE_LAST | UPSIZE_ALL)` – a larger
  stock sheet for a usable remnant.** After the solver has found the plan with
  the least material, a sheet may be moved to a larger available format when
  that turns its waste into a remnant that meets the `Remnants` threshold:
  candidates are valued by net material (format area − marked remnant area),
  the sheet moves only on a strict improvement and ties go to the smaller
  format, so a full sheet never grows. `UPSIZE_LAST` considers only the
  leftover sheet (lowest utilisation – in practice the last, partially filled
  one), `UPSIZE_ALL` every sheet; default `UPSIZE_NONE`. Pieces never move;
  the result is rebuilt (format, utilisation, cuts, stock usage). Available on
  `auto()`, `atLeast()` and `defined()`. Internal: `FormatUpsizer`, the mirror
  image of `FormatReducer`.
- `Remnants::atLeast(width, height)`: minimum remnant size in both
  dimensions, in the job's orientation – orientation-bound with channels,
  either orientation with `Grain::NONE`. `Remnants::auto(minSide)` is the
  square case; `Remnants::$minSide` is deprecated in favour of `$minWidth` /
  `$minHeight`.
- Result: `SheetPlan::$upsizedFrom` / `sheets[].upsized_from` (the format a
  sheet had before it was enlarged, else `null`) and
  `NestingResult::$netMaterialM2` / `totals.net_material_m2` (material less
  marked remnants, `null` when marking is off). `toLegacyArray()` is
  unchanged. `PdfReport` shows the net material in the statistics and notes
  the original format in the header of an enlarged sheet (label keys
  `stat_net_material`, `sheet_upsized`).
- `Render\PdfReport`: the summary statistics show the total material
  consumption (area of all sheets used, `NestingResult::$materialM2`) right
  after Waste. New label key `stat_material` (`Material consumption` /
  `Spotřeba materiálu`); a custom label map without it falls back to the
  English text.
- `Render\PdfReport`: new constructor argument `groupIdenticalSheets`
  (default `false`, output unchanged). When on, sheets with the same format
  (and the same original format, see `upsized_from`) and the same pieces and
  remnants at the same positions share one diagram; its header gives the
  number of sheets (`24 pcs (302.4 m²)`) and the totals over them. Diagrams
  are numbered as plans, the pieces and remnants tables refer to plan numbers
  (column label key `col_plans`: `Plan` / `Plán`), and `[N]` in a diagram is
  the row of the piece in the pieces table.
- `Render\PdfReport`: new constructor argument `oneSheetPerPage` (default
  `false`, output unchanged). When on, every diagram gets its own A4
  landscape page (the summary stays portrait), about 1.5× larger with
  proportionally larger labels.

### Fixed

- **Auto remnant marking finds the largest rectangle that meets the
  minimum.** It used to stop as soon as the largest empty rectangle of a sheet
  fell below the minimum, so a thin strip (e.g. 6000 × 150) hid a usable block
  beside it. Placements are unchanged; a job's `remnants` / `remnant_m2` can
  only grow (the golden fixtures changed in those keys only).
- Documented that the `Objective::bestRemnant()` tie-break is independent of
  the `Remnants` option (its rectangle is measured without the minimum or
  formats) and that no objective trades material for a remnant.

## 2.0.0 – 2026-10-27

### New API

- `NestingJob::create(material, objective, search, remnants)` with
  `Options\Material` (`Grain::NONE | ALONG_WIDTH | ALONG_HEIGHT`),
  `Options\Objective` (`minWaste()` / `minCuts()` / `bestRemnant()`),
  `Options\Search` (`singlePass()` replaces the former `smallest_fit`) and
  `Options\Remnants` (`none()` / `auto()` / `defined()`);
  `SheetNester::solve(NestingJob)` returns a typed `Result\NestingResult`
  (`SheetPlan`, `Placement`, `CutPlan`, `Cut`, `Remnant`, `StockUsage`,
  `UnplacedPieces`). `toArray()` / `fromArray()` for JSON,
  `toLegacyArray()` / `fromLegacyArray()` for the 1.x array shape (a stored
  1.x `strategy: 'smallest_fit'` is read as `min_waste`).
- The 1.x constructor, `addStockFormat()`, `addPiece()`, `addRemnantFormat()`,
  `calculate()`, `toSvg()` and the `STRATEGY_*` / `REMNANT_*` constants are
  removed – see [UPGRADE.md](UPGRADE.md). `SheetNesterPdf` remains as a
  deprecated wrapper over `Render\PdfReport`.
- Piece labels: `addPiece(..., label: 'A')` – carried to every `Placement`
  and to `UnplacedPieces` (grouped by size and label).
- Machine-readable unplaced reasons: `UnplacedPieces::$reasonCode`
  (`stock` / `too_large`) with an English `reason()`; the Czech 1.x texts
  remain in `toLegacyArray()`.
- Unlimited stock: `addStock(width, height)` without `quantity`;
  `StockUsage::$remaining` is `null` then.
- `Render\SvgRenderer` (public): renders a `SheetPlan`, texts via a label map
  (`LABELS_EN` default, `LABELS_CS`), shows piece labels, and its output is
  well-formed XML – 1.x `toSvg()` emitted `&nbsp;`, so a standalone `.svg`
  file could not be opened (now `&#160;`).
- `Render\PdfReport` replaces `SheetNesterPdf`: takes a `NestingResult`,
  texts via a label map (`LABELS_EN` / `LABELS_CS`), `html()` without mPDF,
  dimension columns in input order (width, height – 1.x printed "Délka" =
  height first), new rows for saw cuts and seal length, a label column, `∞`
  for unlimited stock, meta keys `company_id` / `company_vat_id`
  (`SheetNesterPdf` maps `company_ico` / `company_dic`). The objective row
  shows a translated name (`objective_min_waste` … label keys) – 1.x printed
  the raw identifier (`Strategie: min_waste`).

### Changed values

- **Seal length follows the grain axis.** Per piece the two edges
  perpendicular to the channels: `Grain::ALONG_WIDTH` → 2 × height,
  `Grain::ALONG_HEIGHT` → 2 × width, `Grain::NONE` → `null` (`0.0` in the
  legacy array). 1.x always summed height × 2 regardless of direction, which
  is only right for channels along the width; for the former `'vertical'`
  (= `ALONG_HEIGHT`, the e-shop convention where the length along the
  channels is the height) the value changes to Σ width × 2. Placement, cuts
  and SVG geometry are unchanged. Seal is reported per sheet
  (`SheetPlan::$sealMm`); the legacy array keeps it in `cuts.seal_mm`.
- **Saw cuts with a kerf are no longer double-counted.** 1.x reported the
  right edge of a piece and the left edge of its neighbour (one kerf apart)
  as two cuts – with a 4 mm kerf that inflated the cut count by about 80 %.
  The edge after the kerf strip now snaps to the same coordinate, so one
  blade pass is one cut. `cutCount` / `cutsMm` (and therefore the decisions
  of `Objective::minCuts()`) change only for jobs with `kerf > 0`.

### Internals

- `SheetNester` (1 800 lines) split into `Internal\GuillotinePacker`
  (placement + lookahead), `SheetEliminator`, `FormatReducer`, `Solver`
  (ordering loop), `Scorer` (objective cost / tie-break), `CutCalculator`,
  `RemnantFinder`, `ResultBuilder`; model classes moved to `Internal\`.
  Golden fixtures, SVG and PDF HTML identical after the split.
- Test suite (PHPUnit 9.6–12, PHP 8.0+, `composer test`): golden fixtures of
  the reference job for all objectives, kerf, free rotation, remnant modes
  and unplaced pieces; property-based invariants on random jobs; feature,
  render and PDF smoke tests. CI on PHP 8.0–8.5 (GitHub Actions).

## 1.0.1 – 2026-10-27

- Minimum PHP version lowered from 8.1 to 8.0. The code uses no syntax,
  function or class newer than PHP 8.0 (verified by a token scan); the stable
  `usort` that determinism relies on is guaranteed since PHP 8.0. PHP 8.0.30
  (Linux) and 8.5.9 (Windows) give identical results, SVG and PDF HTML for all
  strategies, grain directions and remnant modes.

## 1.0.0 – 2026-10-26

- Extracted from the Bridge application (`App\Model\Multiplast\Nesting`) into
  a standalone package with the `Multiplast\SheetNester` namespace. Algorithm
  unchanged – identical result, SVG and PDF HTML on the golden dataset and
  further variants (all strategies, kerf, grain direction, remnant modes,
  unplaced pieces).
- Helper classes in separate files (PSR-4), marked `@internal`.
- `SheetNesterPdf`: mPDF is an optional dependency (`suggest`); a clear
  exception without it.
