# Changelog

## Nevydáno

- Testovací sada (PHPUnit 9.6–12, PHP 8.0+, `composer test`): golden fixtures referenčního
  zadání pro všechny strategie, prořez, volnou rotaci, režimy zbytků a neumístěné dílce;
  property-based invarianty na náhodných zadáních; testy jednotlivých funkcí; smoke test PDF.
- CI přes GitHub Actions na PHP 8.0–8.5.
- Vnitřní rozdělení třídy `SheetNester` (1 800 řádků) na `Internal\GuillotinePacker`
  (umísťování + lookahead), `SheetEliminator`, `FormatReducer`, `Solver` (smyčka průchodů),
  `Objective` (cílová funkce strategie), `CutCalculator`, `RemnantFinder`, `ResultBuilder`
  a `Render\SvgRenderer` (kreslí z pole výsledku); pomocné třídy přesunuty do `Internal\`.
  Veřejné API ani výstup se nemění – golden fixtures, SVG i PDF HTML jsou identické.
- Nové API 2.0: `NestingJob::create(material, objective, search, remnants)` s `Options\Material`
  (`Grain::NONE | ALONG_WIDTH | ALONG_HEIGHT`), `Options\Objective` (`minWaste()` / `minCuts()` /
  `bestRemnant()`), `Options\Search` (`singlePass()` ≙ dřívější `smallest_fit`) a
  `Options\Remnants` (`none()` / `auto()` / `defined()`); `SheetNester::solve(NestingJob)` vrací
  typovaný `Result\NestingResult` (`SheetPlan`, `Placement`, `CutPlan`, `Cut`, `Remnant`,
  `StockUsage`, `UnplacedPieces`). `NestingResult::toLegacyArray()` dává pole v tvaru 1.x.
  Konstruktor 1.x, `addStockFormat()`, `addPiece()`, `addRemnantFormat()`, `calculate()`
  a `toSvg()` zůstávají jako deprecated adaptér nad `solve()` (`'horizontal'` = `ALONG_WIDTH`,
  `'vertical'` = `ALONG_HEIGHT`); výstup beze změny.

## 1.0.1 – 2026-10-27

- Minimální verze PHP snížena z 8.1 na 8.0. Kód nepoužívá žádnou syntaxi, funkci ani třídu
  novější než PHP 8.0 (ověřeno tokenovou kontrolou zdrojáků); stabilní `usort`, na kterém
  stojí determinismus výsledků, garantuje PHP právě od verze 8.0. Běh na PHP 8.0.30 (Linux)
  a 8.5.9 (Windows) dává pro všechny strategie, směry vláken a režimy zbytků identický
  výsledek, SVG i PDF HTML.

## 1.0.0 – 2026-10-26

- Vyčleněno z aplikace Bridge (`App\Model\Multiplast\Nesting`) do samostatného balíčku
  s namespace `Multiplast\SheetNester`. Algoritmus beze změny – na golden datasetu
  a dalších variantách (všechny strategie, kerf, směr vláken, režimy zbytků,
  neumístěné dílce) identický výsledek, SVG i PDF HTML.
- Pomocné třídy v samostatných souborech (PSR-4) a označené `@internal`.
- `SheetNesterPdf`: mPDF je volitelná závislost (`suggest`); bez něj srozumitelná výjimka.
