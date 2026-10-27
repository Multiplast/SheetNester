# Upgrading from 1.x to 2.0

2.0 replaces the constructor-and-setters API with a job object and a typed
result. The engine is the same: the cutting plans of 1.x are reproduced bit
for bit (the golden tests prove it) – only the seal length changed, see
"Behaviour changes".

## API mapping

| 1.x | 2.0 |
|---|---|
| `new SheetNester(kerf: 4, grainDirection: 'vertical', formatStrategy: 'min_waste', restarts: 300, seed: 42, timeBudgetMs: 8000, remnantMode: 'auto', minRemnantSide: 200)` | `NestingJob::create(material: new Material(kerf: 4, grain: Grain::ALONG_HEIGHT), objective: Objective::minWaste(), search: new Search(restarts: 300, seed: 42, timeBudgetMs: 8000), remnants: Remnants::auto(minSide: 200))` |
| `grainDirection: 'none'` | `Grain::NONE` – pieces may be rotated, nothing to seal |
| `grainDirection: 'horizontal'` | `Grain::ALONG_WIDTH` – channels run along the width (X) |
| `grainDirection: 'vertical'` | `Grain::ALONG_HEIGHT` – channels run along the height (Y) |
| `formatStrategy: 'smallest_fit'` | `Objective::minWaste()` + `Search::singlePass()` |
| `formatStrategy: 'min_waste'` / `'min_cuts'` / `'best_remnant'` | `Objective::minWaste()` / `Objective::minCuts(cutCostM2: 0.05)` / `Objective::bestRemnant()` |
| `restarts`, `seed`, `timeBudgetMs` | `new Search(restarts:, seed:, timeBudgetMs:)` |
| `remnantMode: 'none'` / `'auto'` + `minRemnantSide` / `'defined'` + `addRemnantFormat(w, h)` | `Remnants::none()` / `Remnants::auto(minSide: 200)` / `Remnants::defined([[w, h], ...])` |
| `addStockFormat(w, h, stock)` | `->addStock(w, h, quantity: stock)` – omit `quantity` for unlimited stock |
| `addPiece(w, h, qty)` | `->addPiece(w, h, quantity: qty, label: 'optional')` |
| `calculate(): array` | `(new SheetNester())->solve($job): NestingResult` |
| the result array | `$result->toArray()` (new shape) or `$result->toLegacyArray()` (the 1.x shape, key for key) |
| `toSvg($i)` | `(new SvgRenderer(SvgRenderer::LABELS_CS))->render($result->sheets[$i])` – default texts are English |
| `new SheetNesterPdf($array, $meta)` | `new PdfReport($result, $meta, PdfReport::LABELS_CS)` – `SheetNesterPdf` still works (deprecated) with the 1.x array or a `NestingResult`; meta keys `company_ico` / `company_dic` became `company_id` / `company_vat_id` |
| `SheetNester::STRATEGY_*`, `REMNANT_*`, `MIN_WASTE_RESTARTS` | `Objective::MIN_WASTE` …, `Remnants::NONE` …, `Search` defaults |

Which `Grain` value replaces your `'vertical'` depends on how your data is
oriented: `width` is the X and `height` the Y dimension of both sheets and
pieces. If the sheet length along the channels is entered as `height`
(e.g. 2100 × 6000), use `Grain::ALONG_HEIGHT`; if it is entered as `width`
(6000 × 2100), use `Grain::ALONG_WIDTH`. Placement is identical either way;
only the seal length differs.

## Behaviour changes

- **Seal length follows the grain axis.** The channel ends to seal are the two
  piece edges perpendicular to the channels: `ALONG_WIDTH` → 2 × height per
  piece, `ALONG_HEIGHT` → 2 × width, `NONE` → `null` (`0.0` in the legacy
  array). 1.x always summed height × 2, which is only right for channels along
  the width – with `Grain::ALONG_HEIGHT` the number changes.
- **Saw cuts with a kerf are no longer double-counted.** 1.x reported the
  right edge of a piece and the left edge of its neighbour (one kerf apart) as
  two cuts – with a 4 mm kerf that inflated `total_cuts_count` by about 80 %.
  One blade pass is now one cut; `cutCount` / `cutsMm` (and so the decisions
  of `Objective::minCuts()`) change only for jobs with `kerf > 0`. Placements
  are unchanged.
- Unplaced pieces carry a machine-readable `reasonCode` (`stock`, `too_large`)
  and an English `reason()`; the Czech 1.x texts remain in `toLegacyArray()`.
- The SVG preview is well-formed XML (1.x emitted `&nbsp;`, which broke
  standalone `.svg` files), its default texts are English, and it shows piece
  labels.
- The PDF report lists dimensions in input order (width, height) – 1.x printed
  "Délka" = height first – and gained rows for saw cuts and seal length, a
  label column and `∞` for unlimited stock.

## Result shape: `toArray()` vs the 1.x array

| 1.x key | `toArray()` |
|---|---|
| `sheets[].sheet_index` / `stock_width` / `stock_height` | `sheets[].index` / `width` / `height` |
| `sheets[].used_area_pct` | `sheets[].used_pct` |
| `sheets[].cuts.seal_mm` | `sheets[].seal_mm` |
| `sheets[].cuts.cuts_horizontal[].y / x_start / x_end` | `sheets[].cuts.horizontal[].position / start / end` (+ `axis`) |
| `sheets[].remnant_area_m2` | `sheets[].remnant_m2` (`null` when remnant marking is off) |
| `unplaced_pieces[].reason` | `unplaced[].reason_code` + `reason` + `label` |
| `total_sheets`, `total_material_m2`, `utilization_pct`, `total_waste_pct`, `total_cuts_count`, `total_cuts_mm`, `total_seal_mm`, `total_remnant_m2`, `total_remnant_pct` | `totals.sheets`, `material_m2`, `utilization_pct`, `waste_pct_avg`, `cut_count`, `cuts_mm`, `seal_mm`, `remnant_m2`, `remnant_pct` (nullable where not applicable) |
| `strategy`, `permutations_tested`, `time_budget_hit`, `calculation_time_ms` | `objective`, `passes`, `time_budget_hit`, `calculation_ms` |

`NestingResult::fromArray()` and `fromLegacyArray()` rebuild the object from
either shape (e.g. from JSON stored in a database) so a PDF can be rendered
later. A stored 1.x `strategy: 'smallest_fit'` is read as objective
`min_waste` – 2.0 has no such objective, the quick estimate is `minWaste()`
with a single pass.
